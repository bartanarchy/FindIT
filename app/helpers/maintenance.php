<?php

// Shown instead of a PHP crash when the database cannot be reached (for example it was
// deleted and is waiting to be restored). Signed-in admins are sent straight to Backup
// Management, which works without the database. Everyone else sees this page; the
// emergency admin account (app/helpers/recovery.php) can sign in from here.

require_once __DIR__ . '/auth.php';

function fiDatabaseDownPage(): void
{
    $base = appBasePath();

    if (isLoggedIn() && ($_SESSION['user']['role'] ?? '') === 'admin') {
        header('Location: ' . $base . '/admin/backups.php#restore');
        exit;
    }

    http_response_code(503);
    header('Retry-After: 300');
    $css = htmlspecialchars($base . '/public/assets/css/style.css', ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FindIT is under maintenance</title>
    <link rel="stylesheet" href="<?= $css ?>">
</head>
<body class="fi-body">
<main class="fi-main">
    <div class="fi-container" style="max-width:640px;padding-top:12vh">
        <p class="fi-eyebrow">FindIT</p>
        <h1>We'll be right back.</h1>
        <p>The website is being maintained and its data is being recovered. Please try again in a few minutes.</p>
        <p class="small text-muted mb-2">For the administrator: sign in with the emergency account to open Backup Management and restore the database.</p>
        <p><a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($base . '/public/recovery-login.php', ENT_QUOTES, 'UTF-8') ?>">Administrator recovery sign-in</a></p>
        <p class="small text-muted mb-1">No emergency account yet? Run this on the server computer:</p>
        <p class="small"><code>php scripts\restore.php</code></p>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}
