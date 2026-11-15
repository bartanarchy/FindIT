<?php

// Sends the signed-in student's own profile photo. The photo is stored encrypted in the
// database, so it is decrypted here and never served as a normal file.

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/avatar.php';

if (!isLoggedIn() || ($_SESSION['user']['role'] ?? '') !== 'student' || !$pdo) {
    http_response_code(403);
    exit;
}

// Release the session lock so other requests are not slowed down while the image is sent.
$userId = (int) $_SESSION['user']['id'];
session_write_close();

try {
    $photo = FiAvatar::photo($pdo, $userId);
} catch (Throwable $e) {
    error_log('[FindIT] Failed to read the profile photo: ' . $e->getMessage());
    $photo = null;
}

if ($photo === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $photo['mime']);
header('Content-Length: ' . strlen($photo['data']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400'); // the page adds ?v=<time>, so a new photo is fetched again
header("Content-Security-Policy: default-src 'none'; sandbox");

echo $photo['data'];
