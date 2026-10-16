
<?php

// We start the session because the form token is stored in the session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function generateCsrfToken(): string
{
    // Reuse the token while the current session is active.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $submittedToken): bool
{
    // The submitted token must match the one saved in this session.
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return is_string($sessionToken)
        && $sessionToken !== ''
        && $submittedToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}
