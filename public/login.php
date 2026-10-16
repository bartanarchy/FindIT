
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FindIT</title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="card border-0 shadow-sm rounded-4 w-100" style="max-width: 450px;">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <a href="index.php" class="text-decoration-none text-dark">
                    <h2 class="fw-bold mb-2">FindIT</h2>
                </a>
                <p class="text-secondary mb-0">
                    Welcome back! Sign in to your account.
                </p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= escapeLoginHtml($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeLoginHtml($csrfToken) ?>"
                >

                <div class="mb-3">
                    <label for="email" class="form-label">Campus Email</label>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        maxlength="100"
                        value="<?= escapeLoginHtml($email) ?>"
                        placeholder="you@example.com"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    Sign In
                </button>
            </form>

            <p class="text-center text-secondary mt-4 mb-0">
                Don't have an account?
                <a href="register.php" class="text-decoration-none">Create one</a>
            </p>

            <p class="text-center mt-3 mb-0">
                <a href="index.php" class="text-secondary small text-decoration-none">
                    Back to homepage
                </a>
            </p>

        </div>
    </div>
</main>

<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
