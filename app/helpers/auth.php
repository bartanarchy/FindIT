
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

function requireLogin(): void
{
    // A visitor without a valid login needs to sign in first.
    if (!isLoggedIn()) {
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
