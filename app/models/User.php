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
}
