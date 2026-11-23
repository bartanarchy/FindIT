<?php

// We start the session here if the current page hasn't started it yet.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function appBasePath(): string
{
    // Pages live in /public or /admin, so the project root is one folder above them.
    // This keeps redirects working no matter which folder the project is placed in.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $root = dirname(dirname($script));

    return ($root === '/' || $root === '.' || $root === '\\') ? '' : rtrim($root, '/');
}

function isLoggedIn(): bool
{
    // If the session has a user ID and role, we treat this person as logged in.
    return isset($_SESSION['user']['id'], $_SESSION['user']['role']);
}

function currentUser(): ?array
{
    // Some pages need the current user's name or ID, so we return the session data.
    return isLoggedIn() ? $_SESSION['user'] : null;
}

function isRecoverySession(): bool
{
    // The emergency admin (see recovery.php) is not a database account.
    return !empty($_SESSION['user']['recovery']);
}

function recoverySessionFresh(): bool
{
    $started = (int) ($_SESSION['user']['recovery_started'] ?? 0);

    return $started > 0 && (time() - $started) <= 1800;
}

function recoverySessionAllowed(): bool
{
    // An emergency session may only open Backup Management, and only for 30 minutes.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return substr($script, -strlen('/admin/backups.php')) === '/admin/backups.php'
        && recoverySessionFresh();
}

function requireLogin(): void
{
    // A visitor without a valid login needs to sign in first.
    if (!isLoggedIn()) {
        header('Location: ' . appBasePath() . '/public/login.php');
        exit;
    }

    // Emergency sessions must not reach anything else, for example after the database is back.
    if (isRecoverySession() && !recoverySessionAllowed()) {
        // Still valid: send it back to the only page it may use. Expired: end the session.
        if (recoverySessionFresh()) {
            header('Location: ' . appBasePath() . '/admin/backups.php#restore');
            exit;
        }

        $_SESSION = [];
        session_destroy();
        header('Location: ' . appBasePath() . '/public/login.php');
        exit;
    }
}

function requireRole(string $requiredRole): void
{
    // First, make sure the visitor has signed in.
    requireLogin();

    // A logged-in student still shouldn't be able to open an admin-only page.
    if ($_SESSION['user']['role'] !== $requiredRole) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function redirectLoggedInUser(): void
{
    // Send users to the correct area if they're already signed in.
    if (!isLoggedIn()) {
        return;
    }

    if ($_SESSION['user']['role'] === 'admin') {
        header('Location: ' . appBasePath() . '/admin/index.php');
        exit;
    }

    header('Location: ' . appBasePath() . '/public/index.php');
    exit;
}
