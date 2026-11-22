<?php

// Emergency administrator sign-in. Works without the database, and only while the
// database cannot be reached. Normal students and admins use login.php.

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/recovery.php';

// Database is fine: there is nothing to recover, use the normal sign-in page.
if (!defined('FINDIT_DB_DOWN')) {
    header('Location: login.php');
    exit;
}

// Already signed in as the emergency admin: go straight to Backup Management.
if (isLoggedIn() && !empty($_SESSION['user']['recovery'])) {
    header('Location: ../admin/backups.php#restore');
    exit;
}

$errorMessage = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $result = fiRecoverySignIn($email, $password);
        if ($result['success']) {
            header('Location: ../admin/backups.php#restore');
            exit;
        }
        $errorMessage = $result['message'];
    }
}

$csrfToken = generateCsrfToken();

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Administrator recovery | FindIT');
fiPublicNav(null, '');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card">
        <div class="fi-auth-head">
            <a href="login.php" class="fi-brand">FindIT<span>.</span></a>
            <p>Administrator recovery. The database is not available, so only the emergency administrator can sign in to restore it.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <form method="POST" action="recovery-login.php" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Emergency admin email</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="100"
                       value="<?= fiE($email) ?>" autocomplete="username" required>
                <div class="invalid-feedback">Please enter the emergency admin email.</div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" required>
                <div class="invalid-feedback">Please enter your password.</div>
            </div>

            <button type="submit" class="btn btn-primary w-100" data-loading-text="Signing in...">Sign in</button>
        </form>

        <p class="fi-auth-foot"><a href="index.php" class="small">&larr; Back to homepage</a></p>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
