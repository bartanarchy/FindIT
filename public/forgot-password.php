<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

redirectLoggedInUser();

$authController = new AuthController();
$errorMessage = '';
$successMessage = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $result = $authController->requestPasswordReset($email);

        if ($result['success']) {
            $successMessage = $result['message'];
            $email = '';
        } else {
            $errorMessage = $result['message'] ?? 'Unable to process your request.';
        }
    }
}

$csrfToken = generateCsrfToken();

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Forgot password | FindIT');
fiPublicNav(null, 'login');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card">

        <div class="fi-auth-head">
            <a href="index.php" class="fi-brand">FindIT<span>.</span></a>
            <p>Enter your Polinema student email and we'll send you a reset link.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <?php fiAlert('success', $successMessage); ?>
        <?php endif; ?>

        <form method="POST" action="forgot-password.php" class="needs-validation" novalidate>
            <input
                type="hidden"
                name="csrf_token"
                value="<?= fiE($csrfToken) ?>">

            <div class="mb-4">
                <label for="email" class="form-label">Campus email</label>
                <input
                    type="email"
                    class="form-control"
                    id="email"
                    name="email"
                    maxlength="100"
                    value="<?= fiE($email) ?>"
                    placeholder="NIM@student.polinema.ac.id"
                    autocomplete="email"
                    required>
                <div class="form-text">Only Polinema student emails (@student.polinema.ac.id) are supported.</div>
                <div class="invalid-feedback">Please enter your Polinema student email.</div>
            </div>

            <button
                type="submit"
                class="btn btn-primary w-100"
                data-loading-text="Sending reset link...">
                Send reset link
            </button>
        </form>

        <p class="fi-auth-foot">
            Remembered your password?
            <a href="login.php">Sign in</a>
        </p>

        <p class="fi-auth-foot">
            <a href="index.php" class="small">&larr; Back to homepage</a>
        </p>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>