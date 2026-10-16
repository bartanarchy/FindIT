<?php

class Admin
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        // We use the same database connection, but query the admin table separately.
        $this->pdo = $pdo;
    }

    public function findByEmail(string $email): ?array
    {
        // Admin accounts are stored separately from student accounts.
        $sql = '
            SELECT
                id_admin,
                email_admin,
                nama_admin,
                password_admin,
                level_access
            FROM public.admin
            WHERE LOWER(email_admin) = LOWER(:email)
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => trim($email),
        ]);

        $admin = $stmt->fetch();

        // If the email doesn't belong to an admin, let the login process handle it.
        return $admin ?: null;
    }

    public function create(
        string $email,
        string $name,
        string $passwordHash,
        string $accessLevel
    ): int {
        // This method can be used by a trusted setup process to create an admin account.
        $sql = '
            INSERT INTO public.admin (
                email_admin,
                nama_admin,
                password_admin,
                level_access
            )
            VALUES (
                :email,
                :name,
                :password,
                :access_level
            )
            RETURNING id_admin
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => trim($email),
            'name' => trim($name),
            'password' => $passwordHash,
            'access_level' => trim($accessLevel),
        ]);

        // Return the new admin ID so the setup process can confirm it was created.
        return (int) $stmt->fetchColumn();
    }
}
