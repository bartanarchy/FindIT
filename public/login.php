
<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// if someone is already logged in, send them to the right page.
redirectLoggedInUser();

$authController = new AuthController();
$errorMessage = '';
$email = '';

// Handle the login form when it gets submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // we keep the email so the user doesn't have to type it again after an error.
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // check the form token before processing the login.
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $result = $authController->login($email, $password);

        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        }

        $errorMessage = $result['message'] ?? 'Login failed. Please try again.';
    }
}

// create a fresh token for the form.
$csrfToken = generateCsrfToken();

function escapeLoginHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Sign in | FindIT');
fiPublicNav(null, 'login');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card">

        <div class="fi-auth-head">
            <a href="index.php" class="fi-brand">FindIT<span>.</span></a>
            <p>Welcome back! Sign in to your account.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <form method="POST" action="login.php" class="needs-validation" novalidate>
            <input
                type="hidden"
                name="csrf_token"
                value="<?= fiE($csrfToken) ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Campus email</label>
                <input
                    type="email"
                    class="form-control"
                    id="email"
                    name="email"
                    maxlength="100"
                    value="<?= fiE($email) ?>"
                    placeholder="you@example.com"
                    autocomplete="username"
                    required>
                <div class="invalid-feedback">Please enter a valid email address.</div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group has-validation">
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required>
                    <button
                        type="button"
                        class="btn fi-pass-toggle"
                        data-toggle-password="#password"
                        aria-label="Show or hide password">Show</button>
                    <div class="invalid-feedback">Please enter your password.</div>
                </div>
            </div>

            <button
                type="submit"
                class="btn btn-primary w-100"
                data-loading-text="Signing in...">
                Sign in
            </button>
        </form>

        <p class="fi-auth-foot">
            Don't have an account?
            <a href="register.php">Create one</a>
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
