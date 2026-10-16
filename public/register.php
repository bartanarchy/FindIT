
<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// if someone is already logged in, they don't need to register again.
redirectLoggedInUser();

$authController = new AuthController();

$errorMessage = '';
$successMessage = '';
$formData = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'student_id' => '',
];

// Handle the registration form when it gets submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Keep the non-sensitive fields so the user doesn't have to fill them in again.
    $formData['name'] = trim($_POST['name'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['phone'] = trim($_POST['phone'] ?? '');
    $formData['student_id'] = trim($_POST['student_id'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Your session has expired. Please refresh the page and try again.';
    } else {
        // The controller handles validation, password hashing, and database insertion.
        $result = $authController->register([
            'name' => $formData['name'],
            'email' => $formData['email'],
            'phone' => $formData['phone'],
            'student_id' => $formData['student_id'],
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
        ]);

        if ($result['success']) {
            $successMessage = $result['message'];
            $formData = [
                'name' => '',
                'email' => '',
                'phone' => '',
                'student_id' => '',
            ];
        } else {
            $errorMessage = $result['message'] ?? 'Registration failed. Please try again.';
        }
    }
}

// Create a token to protect the registration form.
$csrfToken = generateCsrfToken();

function escapeRegisterHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | FindIT</title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="card border-0 shadow-sm rounded-4 w-100" style="max-width: 520px;">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <a href="index.php" class="text-decoration-none text-dark">
                    <h2 class="fw-bold mb-2">FindIT</h2>
                </a>
                <p class="text-secondary mb-0">
                    Create your student account to get started.
                </p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= escapeRegisterHtml($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert">
                    <?= escapeRegisterHtml($successMessage) ?>
                    <a href="login.php" class="alert-link">Sign in here.</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeRegisterHtml($csrfToken) ?>"
                >

                <div class="mb-3">
                    <label for="name" class="form-label">Full Name</label>
                    <input
                        type="text"
                        class="form-control"
                        id="name"
                        name="name"
                        maxlength="50"
                        value="<?= escapeRegisterHtml($formData['name']) ?>"
                        placeholder="Enter your full name"
                        autocomplete="name"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Campus Email</label>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        maxlength="100"
                        value="<?= escapeRegisterHtml($formData['email']) ?>"
                        placeholder="you@example.com"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input
                        type="tel"
                        class="form-control"
                        id="phone"
                        name="phone"
                        maxlength="15"
                        value="<?= escapeRegisterHtml($formData['phone']) ?>"
                        placeholder="Enter your phone number"
                        autocomplete="tel"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="student_id" class="form-label">Student ID (NIM)</label>
                    <input
                        type="text"
                        class="form-control"
                        id="student_id"
                        name="student_id"
                        maxlength="20"
                        value="<?= escapeRegisterHtml($formData['student_id']) ?>"
                        placeholder="Enter your student ID"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="At least 8 characters"
                        required
                    >
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">
                        Confirm Password
                    </label>
                    <input
                        type="password"
                        class="form-control"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Enter your password again"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    Create Account
                </button>
            </form>

            <p class="text-center text-secondary mt-4 mb-0">
                Already have an account?
                <a href="login.php" class="text-decoration-none">Sign in</a>
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
