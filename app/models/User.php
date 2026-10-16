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
        // look up the student account using its campus email.
        $sql = '
            SELECT
                id_user,
                email_kampus,
                name_user,
                password_user,
                role_user
            FROM public."User"
            WHERE LOWER(email_kampus) = LOWER(:email)
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => trim($email),
        ]);

        $user = $stmt->fetch();

        // null when no student account matches this email.
        return $user ?: null;
    }

    public function create(
        string $email,
        string $name,
        string $passwordHash,
        string $phone,
        string $studentId
    ): int {
        // database stores the password hash, never the original password.
        $sql = '
            INSERT INTO public."User" (
                email_kampus,
                name_user,
                password_user,
                no_telp,
                role_user,
                id_nim
            )
            VALUES (
                :email,
                :name,
                :password,
                :phone,
                :role,
                :student_id
            )
            RETURNING id_user
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => trim($email),
            'name' => trim($name),
            'password' => $passwordHash,
            'phone' => trim($phone),
            'role' => 'student',
            'student_id' => trim($studentId),
        ]);

        // PostgreSQL returns the new student's ID after the insert.
        return (int) $stmt->fetchColumn();
    }

    public function emailExists(string $email): bool
    {
        // We check this first to give the student a clearer registration error.
        $sql = '
            SELECT 1
            FROM public."User"
            WHERE LOWER(email_kampus) = LOWER(:email)
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => trim($email),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function studentIdExists(string $studentId): bool
    {
        // Student IDs are unique too, so one student can't register twice with the same ID.
        $sql = '
            SELECT 1
            FROM public."User"
            WHERE id_nim = :student_id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'student_id' => trim($studentId),
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
