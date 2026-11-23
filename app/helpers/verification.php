<?php

// Students must verify their campus email before they can report or claim items.

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/models/EmailVerification.php';

/** Is the signed-in student's campus email verified? Read from the database each time. */
function fiStudentIsVerified(PDO $pdo, int $userId): bool
{
    try {
        return (new EmailVerification($pdo))->isVerified($userId);
    } catch (PDOException $e) {
        error_log('[FindIT] Verification check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Call after requireRole('student') on pages that report or claim items.
 * An unverified student is sent to the profile page, where the Verify button is.
 */
function requireVerifiedStudent(PDO $pdo): void
{
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    if (fiStudentIsVerified($pdo, $userId)) {
        return;
    }

    $_SESSION['profile_flash'] = [
        'section' => 'verify',
        'type' => 'warning',
        'message' => 'Please verify your campus email first. After that you can report found items and claim items.',
    ];
    header('Location: ' . appBasePath() . '/public/profile.php#verify');
    exit;
}
