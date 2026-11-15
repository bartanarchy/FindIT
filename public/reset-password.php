<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

redirectLoggedInUser();

$authController = new AuthController();
$token = trim($_POST['token'] ?? $_GET['token'] ?? '');
$errorMessage = '';
$successMessage = '';
$tokenValid = $authController->validateResetToken($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $result = $authController->resetPassword($token, $password, $confirmPassword);

        if ($result['success']) {
            $successMessage = $result['message'];
            $tokenValid = false;
        } else {
            $errorMessage = $result['message'] ?? 'Could not reset password.';
            $tokenValid = $authController->validateResetToken($token);
        }
    }
}

$csrfToken = generateCsrfToken();

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Reset password | FindIT');
fiPublicNav(null, 'login');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card">

        <div class="fi-auth-head">
            <a href="index.php" class="fi-brand">FindIT<span>.</span></a>
            <p>Choose a new password for your student account.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <?php fiAlert('success', $successMessage); ?>
            <a href="login.php" class="btn btn-primary w-100 mt-2">Go to sign in</a>
        <?php elseif (!$tokenValid): ?>
            <?php fiAlert('danger', 'This password reset link is invalid or has expired.'); ?>
            <a href="forgot-password.php" class="btn btn-primary w-100 mt-2">Request a new link</a>
        <?php else: ?>
            <form method="POST" action="reset-password.php" class="needs-validation" novalidate>
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= fiE($csrfToken) ?>">
                <input
                    type="hidden"
                    name="token"
                    value="<?= fiE($token) ?>">

                <div class="mb-3">
                    <label for="password" class="form-label">New password</label>
                    <div class="input-group has-validation">
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="At least 8 characters"
                            required>
                        <button
                            type="button"
                            class="btn fi-pass-toggle"
                            data-toggle-password="#password"
                            aria-label="Show or hide password">Show</button>
                        <div class="invalid-feedback">Password must be at least 8 characters.</div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm new password</label>
                    <div class="input-group has-validation">
                        <input
                            type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="Re-enter your new password"
                            required>
                        <button
                            type="button"
                            class="btn fi-pass-toggle"
                            data-toggle-password="#confirm_password"
                            aria-label="Show or hide confirm password">Show</button>
                        <div class="invalid-feedback">Please confirm your new password.</div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                    data-loading-text="Updating password...">
                    Update password
                </button>
            </form>
        <?php endif; ?>

        <p class="fi-auth-foot">
            <a href="login.php" class="small">&larr; Back to sign in</a>
        </p>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>