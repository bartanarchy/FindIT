<?php

// Emergency ("break-glass") administrator sign-in.
//
// Normal admin accounts live in the database. If the database is deleted or broken,
// nobody could sign in to restore it. This file gives ONE emergency admin account whose
// credentials are kept in .env (as a password hash), and it works ONLY while the
// database cannot be reached. Students are never affected: their accounts are in the
// database, so they still cannot sign in until the restore is finished.
//
// A recovery session can open nothing except Backup Management (see auth.php).

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/backup/bootstrap.php';

const FI_RECOVERY_SESSION_TTL = 1800;   // 30 minutes
const FI_RECOVERY_MAX_FAILS   = 5;      // wrong attempts before a lock
const FI_RECOVERY_LOCK_SECONDS = 900;   // 15 minutes

/** Emergency account from .env: [email, password hash]. Empty strings when not set up. */
function fiRecoveryCredentials(): array
{
    return [
        strtolower(BackupConfig::env('ADMIN_RECOVERY_EMAIL')),
        BackupConfig::env('ADMIN_RECOVERY_PASSWORD_HASH'),
    ];
}

function fiRecoveryClientKey(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * Read-modify-write the attempt counters under a file lock.
 * The file lives in the backup folder, which is outside the website.
 */
function fiRecoveryThrottle(callable $change): array
{
    $path = BackupConfig::dir('logs') . '/recovery-attempts.json';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return [];
    }

    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    $data = is_array($data) ? $data : [];

    // Forget old entries so the file stays small.
    $now = time();
    foreach ($data as $key => $row) {
        if (($row['last'] ?? 0) < $now - 86400) {
            unset($data[$key]);
        }
    }

    $data = $change($data, $now);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $data;
}

function fiRecoveryLockedUntil(): int
{
    $key = fiRecoveryClientKey();
    $until = 0;
    fiRecoveryThrottle(static function (array $data, int $now) use ($key, &$until): array {
        $until = (int) ($data[$key]['locked_until'] ?? 0);
        return $data;
    });
    return $until > time() ? $until : 0;
}

/** Try to sign in with the emergency account. Returns ['success' => bool, 'message' => string]. */
function fiRecoverySignIn(string $email, string $password): array
{
    if (!defined('FINDIT_DB_DOWN')) {
        return ['success' => false, 'message' => 'Emergency sign-in is only available while the database is down. Please use the normal sign-in page.'];
    }

    [$savedEmail, $savedHash] = fiRecoveryCredentials();
    if ($savedEmail === '' || $savedHash === '') {
        return ['success' => false, 'message' => 'Emergency sign-in is not set up. Run "php scripts/restore.php" on the server instead.'];
    }

    $lockedUntil = fiRecoveryLockedUntil();
    if ($lockedUntil > 0) {
        $minutes = max(1, (int) ceil(($lockedUntil - time()) / 60));
        return ['success' => false, 'message' => 'Too many failed attempts. Try again in about ' . $minutes . ' minute(s).'];
    }

    // Always run password_verify so a wrong email and a wrong password take the same time.
    $passwordOk = password_verify($password, $savedHash);
    $emailOk = hash_equals($savedEmail, strtolower(trim($email)));

    if (!$passwordOk || !$emailOk) {
        $key = fiRecoveryClientKey();
        fiRecoveryThrottle(static function (array $data, int $now) use ($key): array {
            $row = $data[$key] ?? ['fails' => 0, 'locked_until' => 0];
            $row['fails'] = (int) $row['fails'] + 1;
            $row['last'] = $now;
            if ($row['fails'] >= FI_RECOVERY_MAX_FAILS) {
                $row['locked_until'] = $now + FI_RECOVERY_LOCK_SECONDS;
                $row['fails'] = 0;
            }
            $data[$key] = $row;
            return $data;
        });
        error_log('[FindIT] Failed emergency admin sign-in from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $key = fiRecoveryClientKey();
    fiRecoveryThrottle(static function (array $data) use ($key): array {
        unset($data[$key]);
        return $data;
    });

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => 0,
        'name' => 'Emergency admin',
        'email' => $savedEmail,
        'role' => 'admin',
        'access_level' => 'recovery',
        'recovery' => true,
        'recovery_started' => time(),
    ];

    error_log('[FindIT] Emergency admin signed in from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return ['success' => true];
}

/** Clear the session and its cookie (works without the database). */
function fiClearSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
