<?php

// One-time links that prove a student can read their campus mailbox.
// Only a SHA-256 hash of the token is stored, so a copy of the table cannot be used to verify.

class EmailVerification
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isVerified(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT email_verified_at IS NOT NULL FROM public."User" WHERE id_user = :id'
        );
        $stmt->execute(['id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /** When the last link was created (to stop people from sending many emails in a row). */
    public function secondsSinceLastLink(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXTRACT(EPOCH FROM (NOW() - MAX(created_at)))::int
             FROM public.email_verifications WHERE id_user = :id'
        );
        $stmt->execute(['id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    public function createToken(int $userId, string $tokenHash, int $ttlMinutes = 60): void
    {
        $this->pdo->beginTransaction();

        try {
            // An older unused link stops working when a new one is sent.
            $this->pdo->prepare(
                'UPDATE public.email_verifications SET used_at = NOW()
                 WHERE id_user = :id AND used_at IS NULL'
            )->execute(['id' => $userId]);

            $this->pdo->prepare(
                'INSERT INTO public.email_verifications (id_user, token_hash, expires_at)
                 VALUES (:id, :hash, NOW() + make_interval(mins => :ttl))'
            )->execute(['id' => $userId, 'hash' => $tokenHash, 'ttl' => $ttlMinutes]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Use a link: marks it used and the account verified in one transaction.
     * Returns the user id, or null when the link is wrong, used or expired.
     */
    public function consume(string $tokenHash): ?int
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE public.email_verifications
                 SET used_at = NOW()
                 WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
                 RETURNING id_user'
            );
            $stmt->execute(['hash' => $tokenHash]);
            $userId = $stmt->fetchColumn();

            if ($userId === false) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->prepare(
                'UPDATE public."User" SET email_verified_at = COALESCE(email_verified_at, NOW())
                 WHERE id_user = :id'
            )->execute(['id' => (int) $userId]);

            $this->pdo->commit();
            return (int) $userId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
