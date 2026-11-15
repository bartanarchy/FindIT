<?php

class User
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        // keep the database connection here so every method can use it.
        $this->pdo = $pdo;
    }

    public function findByEmail(string $email): ?array
    {
        // The email is stored encrypted, so we look it up through its blind index
        // (a keyed hash) instead of comparing the text itself.
        $sql = '
            SELECT
                id_user,
                email_kampus,
                name_user,
                password_user,
                role_user
            FROM public."User"
            WHERE email_bidx = :bidx
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'bidx' => Crypto::blindIndex(normalizeCampusEmail($email)),
        ]);

        $user = $stmt->fetch();

        // null when no student account matches this email.
        return $user ? Crypto::decryptRow($user) : null;
    }

    public function create(
        string $email,
        string $name,
        string $passwordHash,
        string $phone,
        string $studentId
    ): int {
        $email = normalizeCampusEmail($email);
        $studentId = trim($studentId);

        // Personal data is encrypted before it reaches the database.
        // The password is stored only as a hash, never the original password.
        $sql = '
            INSERT INTO public."User" (
                email_kampus,
                email_bidx,
                name_user,
                password_user,
                no_telp,
                role_user,
                id_nim,
                nim_bidx
            )
            VALUES (
                :email,
                :email_bidx,
                :name,
                :password,
                :phone,
                :role,
                :student_id,
                :nim_bidx
            )
            RETURNING id_user
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => Crypto::protect('email_kampus', $email),
            'email_bidx' => Crypto::blindIndex($email),
            'name' => Crypto::protect('name_user', trim($name)),
            'password' => $passwordHash,
            'phone' => Crypto::protect('no_telp', trim($phone)),
            'role' => 'student',
            'student_id' => Crypto::protect('id_nim', $studentId),
            'nim_bidx' => Crypto::blindIndex($studentId),
        ]);

        // PostgreSQL returns the new student's ID after the insert.
        return (int) $stmt->fetchColumn();
    }

    public function emailExists(string $email): bool
    {
        // We check this first to give the student a clearer registration error.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM public."User" WHERE email_bidx = :bidx LIMIT 1'
        );
        $stmt->execute([
            'bidx' => Crypto::blindIndex(normalizeCampusEmail($email)),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function studentIdExists(string $studentId): bool
    {
        // Student IDs are unique too, so one student can't register twice with the same ID.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM public."User" WHERE nim_bidx = :bidx LIMIT 1'
        );
        $stmt->execute([
            'bidx' => Crypto::blindIndex($studentId),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE public."User" SET password_user = :password WHERE id_user = :id_user'
        );
        $stmt->execute([
            'password' => $passwordHash,
            'id_user' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function findById(int $userId): ?array
    {
        // Used by the profile page. Personal columns are decrypted before they are returned.
        $stmt = $this->pdo->prepare(
            'SELECT id_user, email_kampus, name_user, id_nim, password_user
             FROM public."User"
             WHERE id_user = :id_user AND role_user = \'student\'
             LIMIT 1'
        );
        $stmt->execute(['id_user' => $userId]);

        $user = $stmt->fetch();

        return $user ? Crypto::decryptRow($user) : null;
    }

    public function profileStats(int $userId): array
    {
        // My Reports  = items this student reported.
        // My Claims   = claims this student submitted.
        // Returned    = items this student reported that were handed back to their owner.
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM public.item WHERE id_user_pelapor = :u1) AS reports,
                (SELECT COUNT(*) FROM public.claim WHERE id_user_pengaju = :u2) AS claims,
                (SELECT COUNT(*) FROM public.item
                  WHERE id_user_pelapor = :u3 AND status_barang = \'Claimed\') AS returned'
        );
        $stmt->execute(['u1' => $userId, 'u2' => $userId, 'u3' => $userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'reports' => (int) ($row['reports'] ?? 0),
            'claims' => (int) ($row['claims'] ?? 0),
            'returned' => (int) ($row['returned'] ?? 0),
        ];
    }
}