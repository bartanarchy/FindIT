<?php

// Opened from the link in the verification email. It does not need the student to be signed
// in, because the one-time link itself proves that the person can read the campus mailbox.

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/models/EmailVerification.php';

$token = trim((string) ($_GET['token'] ?? ''));
$state = 'invalid';

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $userId = (new EmailVerification($pdo))->consume(hash('sha256', $token));
        $state = $userId !== null ? 'ok' : 'invalid';
    } catch (PDOException $e) {
        error_log('[FindIT] Email verification failed: ' . $e->getMessage());
        $state = 'error';
    }
}

$signedIn = isLoggedIn() && ($_SESSION['user']['role'] ?? '') === 'student';

// Signed in on this browser: go straight to the profile with a message.
if ($state === 'ok' && $signedIn) {
    $_SESSION['profile_flash'] = [
        'section' => 'verify',
        'type' => 'success',
        'message' => 'Your campus email is verified. You can now report found items and claim items.',
    ];
    header('Location: profile.php#verify');
    exit;
}

if ($state !== 'ok') {
    http_response_code($state === 'error' ? 500 : 400);
}

fiHead('Verify email | FindIT');
fiPublicNav(null, '');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card">
        <div class="fi-auth-head">
            <a href="index.php" class="fi-brand">FindIT<span>.</span></a>
            <?php if ($state === 'ok'): ?>
                <p>Your campus email is verified.</p>
            <?php else: ?>
                <p>Email verification</p>
            <?php endif; ?>
        </div>

        <?php if ($state === 'ok'): ?>
            <?php fiAlert('success', 'Thank you! Your account is verified. Sign in to report found items and claim items.'); ?>
            <a href="login.php" class="btn btn-primary w-100">Sign in</a>
        <?php elseif ($state === 'error'): ?>
            <?php fiAlert('danger', 'We could not verify your email right now. Please try again in a moment.'); ?>
        <?php else: ?>
            <?php fiAlert('danger', 'This verification link is not valid. It may have expired or already been used.'); ?>
            <p class="small text-muted">Sign in, open your profile, and press "Send verification email" to get a new link.</p>
            <a href="login.php" class="btn btn-primary w-100">Go to sign in</a>
        <?php endif; ?>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
