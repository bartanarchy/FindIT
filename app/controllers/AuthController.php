<?php

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Admin.php';

class AuthController
{
    private PDO $pdo;
    private User $userModel;
    private Admin $adminModel;

    public function __construct()
    {
        // we start the session here so login information can be shared across pages.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        global $pdo;
        $this->pdo = $pdo;
        $this->userModel = new User($this->pdo);
        $this->adminModel = new Admin($this->pdo);
    }

    public function register(array $data): array
    {
        // we collect and clean up the registration input before checking it.
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $studentId = trim($data['student_id'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        // we don't want to create an account if any required field is missing.
        if (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $studentId === '' ||
            $password === '' ||
            $confirmPassword === ''
        ) {
            return [
                'success' => false,
                'message' => 'Please complete all required fields.',
            ];
        }

        // we only accept valid email addresses and reasonable input lengths.
        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 100 ||
            strlen($name) > 50 ||
            strlen($phone) > 15 ||
            strlen($studentId) > 20
        ) {
            return [
                'success' => false,
                'message' => 'Please check your information and try again.',
            ];
        }

        // both password fields must match before we continue.
        if ($password !== $confirmPassword) {
            return [
                'success' => false,
                'message' => 'The passwords do not match.',
            ];
        }

        // we set a minimum password length so accounts aren't too easy to guess.
        if (strlen($password) < 8) {
            return [
                'success' => false,
                'message' => 'Your password must contain at least 8 characters.',
            ];
        }

        try {
            // we check the unique fields first to show a useful message.
            if ($this->userModel->emailExists($email)) {
                return [
                    'success' => false,
                    'message' => 'This campus email is already registered.',
                ];
            }

            if ($this->userModel->studentIdExists($studentId)) {
                return [
                    'success' => false,
                    'message' => 'This student ID is already registered.',
                ];
            }

            // the original password should never be stored in the database.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $userId = $this->userModel->create(
                $email,
                $name,
                $passwordHash,
                $phone,
                $studentId
            );

            return [
                'success' => true,
                'message' => 'Your account has been created. You can now log in.',
                'user_id' => $userId,
            ];
        } catch (PDOException $e) {
            error_log('[FindIT] Registration failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Registration could not be completed. Please try again.',
            ];
        }
    }

    public function login(string $email, string $password): array
    {
        // we reject empty input before looking up an account.
        $email = trim($email);

        if ($email === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'Please enter your email and password.',
            ];
        }

        try {
            // admin and student accounts live in different tables, so we check both.
            $admin = $this->adminModel->findByEmail($email);

            if ($admin !== null && password_verify($password, $admin['password_admin'])) {
                // refreshing the session ID helps protect the account after login.
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => (int) $admin['id_admin'],
                    'name' => $admin['nama_admin'],
                    'email' => $admin['email_admin'],
                    'role' => 'admin',
                    'access_level' => $admin['level_access'],
                ];

                return [
                    'success' => true,
                    'role' => 'admin',
                    'redirect' => '../admin/index.php',
                ];
            }

            $user = $this->userModel->findByEmail($email);

            if ($user !== null && password_verify($password, $user['password_user'])) {
                // Students get their own session role, so we can protect their pages separately.
                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => (int) $user['id_user'],
                    'name' => $user['name_user'],
                    'email' => $user['email_kampus'],
                    'role' => 'student',
                ];

                return [
                    'success' => true,
                    'role' => 'student',
                    'redirect' => 'index.php',
                ];
            }

            // Keep the error general so we don't reveal which emails have accounts.
            return [
                'success' => false,
                'message' => 'Invalid email or password.',
            ];
        } catch (PDOException $e) {
            error_log('[FindIT] Login failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Login is temporarily unavailable. Please try again.',
            ];
        }
    }

    public function logout(): void
    {
        // We clear the session so the next person using this browser isn't logged in.
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }

        session_destroy();
    }
}
