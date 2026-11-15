<?php

class PasswordReset
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createToken(int $userId, string $tokenHash, int $ttlMinutes = 30): void
    {
        $this->pdo->beginTransaction();

        try {
            // Invalidate any previous unused reset tokens for this student.
            $deleteStmt = $this->pdo->prepare(
                'DELETE FROM public.password_resets WHERE id_user = :id_user AND used_at IS NULL'
            );
            $deleteStmt->execute(['id_user' => $userId]);

            $insertStmt = $this->pdo->prepare(
                "INSERT INTO public.password_resets (id_user, token_hash, expires_at)
                 VALUES (:id_user, :token_hash, NOW() + (:ttl || ' minutes')::interval)"
            );
            $insertStmt->execute([
                'id_user' => $userId,
                'token_hash' => $tokenHash,
                'ttl' => (string) $ttlMinutes,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_reset, id_user
             FROM public.password_resets
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markUsed(int $resetId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE public.password_resets
             SET used_at = NOW()
             WHERE id_reset = :id_reset
               AND used_at IS NULL
               AND expires_at > NOW()'
        );
        $stmt->execute(['id_reset' => $resetId]);

        return $stmt->rowCount() === 1;
    }
}