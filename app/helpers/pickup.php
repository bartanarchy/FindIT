<?php

/**
 * QR pickup tokens.
 *
 * The QR code shown to the student contains a random token (48 hex characters). The database keeps
 * its SHA-256 hash for lookups, plus an encrypted copy so the student can open the QR
 * code again. The short pickup code (PICK-XXXXXXXX) stays the human-readable code.
 */

require_once __DIR__ . '/crypto.php';

/** A new unguessable token: 192 bits from the system's secure random generator. */
function pickupNewToken(): string
{
    return bin2hex(random_bytes(24));
}

function pickupHash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Create the pickup token for an approved claim. One claim can only ever have one token:
 * if it already exists nothing changes. Returns the plain token.
 */
function pickupEnsureToken(PDO $pdo, int $claimId): string
{
    $existing = $pdo->prepare('SELECT token_enc FROM public.pickup_token WHERE id_claim = :claim_id');
    $existing->execute(['claim_id' => $claimId]);
    $stored = $existing->fetchColumn();

    if (is_string($stored) && $stored !== '') {
        return (string) Crypto::reveal('token_enc', $stored);
    }

    $token = pickupNewToken();

    $insert = $pdo->prepare(
        'INSERT INTO public.pickup_token (id_claim, token_hash, token_enc)
         VALUES (:claim_id, :hash, :enc)
         ON CONFLICT (id_claim) DO NOTHING'
    );
    $insert->execute([
        'claim_id' => $claimId,
        'hash' => pickupHash($token),
        'enc' => Crypto::protect('token_enc', $token),
    ]);

    if ($insert->rowCount() === 1) {
        return $token;
    }

    // Another request created it at the same moment: use that one.
    $existing->execute(['claim_id' => $claimId]);
    return (string) Crypto::reveal('token_enc', (string) $existing->fetchColumn());
}
