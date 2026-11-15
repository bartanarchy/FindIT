<?php

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Admin.php';
require_once dirname(__DIR__) . '/models/PasswordReset.php';
require_once dirname(__DIR__) . '/helpers/mailer.php';

class AuthController
{
    private PDO $pdo;
    private User $userModel;
    private Admin $adminModel;
    private PasswordReset $passwordResetModel;

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
        $this->passwordResetModel = new PasswordReset($this->pdo);
    }

    public function register(array $data): array
    {
        // we collect and clean up the registration input before checking it.
        $name = trim($data['name'] ?? '');
        $email = normalizeCampusEmail($data['email'] ?? '');
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

        // only Polinema student emails (NIM@student.polinema.ac.id) may register.
        // gmail, .id, or any other domain is rejected here, on the server.
        if (!isCampusStudentEmail($email)) {
            return [
                'success' => false,
                'message' => CAMPUS_EMAIL_ERROR,
            ];
        }
        $emailNim = campusEmailNim($email);
        
        if (
            !preg_match('/\A[0-9]{12}\z/', $studentId) ||
            $emailNim === null ||
            $studentId !== $emailNim
        ) {
            return [
                'success' => false,
                'message' => 'Your student ID must be 12 digits and match the NIM in your campus email.',
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
            // two people registering the same email/NIM at once: the unique index stops the second.
            if ($e->getCode() === '23505') {
                return [
                    'success' => false,
                    'message' => 'This campus email or student ID is already registered.',
                ];
            }

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
        $email = normalizeCampusEmail($email);

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

            // students must sign in with their campus email. Any other address
            // (gmail, etc.) is refused before we even look in the database.
            if (!isCampusStudentEmail($email)) {
                return [
                    'success' => false,
                    'message' => CAMPUS_EMAIL_ERROR,
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

    public function requestPasswordReset(string $email): array
    {
        $email = normalizeCampusEmail($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please enter a valid campus email address.',
            ];
        }

        if (!isCampusStudentEmail($email)) {
            return [
                'success' => false,
                'message' => CAMPUS_EMAIL_ERROR,
            ];
        }

        // Always return a generic message so we don't reveal which emails are registered.
        $genericSuccess = [
            'success' => true,
            'message' => 'If that campus email is registered, we have sent a password reset link. Please check your inbox.',
        ];

        try {
            $user = $this->userModel->findByEmail($email);

            if ($user === null) {
                return $genericSuccess;
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $this->passwordResetModel->createToken((int) $user['id_user'], $tokenHash, 30);

            $baseUrl = defined('APP_URL') ? APP_URL : 'http://localhost/FindIT/public';
            $resetUrl = $baseUrl . '/reset-password.php?token=' . urlencode($rawToken);

            $sent = sendResetPasswordEmail(
                $user['email_kampus'],
                $user['name_user'] ?? 'Student',
                $resetUrl
            );

            if (!$sent) {
                error_log('[FindIT] Failed to send password reset email to user ID ' . (int) $user['id_user']);
            }

            return $genericSuccess;
        } catch (Throwable $e) {
            error_log('[FindIT] Password reset request error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to process your request right now. Please try again.',
            ];
        }
    }

    public function validateResetToken(string $rawToken): bool
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return false;
        }

        try {
            return $this->passwordResetModel->findValidByTokenHash(hash('sha256', $rawToken)) !== null;
        } catch (PDOException $e) {
            error_log('[FindIT] Token validation error: ' . $e->getMessage());
            return false;
        }
    }

    public function resetPassword(string $rawToken, string $password, string $confirmPassword): array
    {
        $rawToken = trim($rawToken);

        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return [
                'success' => false,
                'message' => 'This password reset link is invalid or has expired.',
            ];
        }

        if ($password === '' || $confirmPassword === '') {
            return [
                'success' => false,
                'message' => 'Please enter and confirm your new password.',
            ];
        }

        if ($password !== $confirmPassword) {
            return [
                'success' => false,
                'message' => 'The passwords do not match.',
            ];
        }

        if (strlen($password) < 8) {
            return [
                'success' => false,
                'message' => 'Your password must contain at least 8 characters.',
            ];
        }

        $tokenHash = hash('sha256', $rawToken);

        try {
            $resetRow = $this->passwordResetModel->findValidByTokenHash($tokenHash);

            if ($resetRow === null) {
                return [
                    'success' => false,
                    'message' => 'This password reset link is invalid or has expired.',
                ];
            }

            $this->pdo->beginTransaction();

            if (!$this->passwordResetModel->markUsed((int) $resetRow['id_reset'])) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'This password reset link has already been used.',
                ];
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $this->userModel->updatePassword((int) $resetRow['id_user'], $passwordHash);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => 'Your password has been updated. You can now sign in.',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[FindIT] Password reset failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Could not update your password. Please try again.',
            ];
        }
    }
}