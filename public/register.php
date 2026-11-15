<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/captcha.php';

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

    } elseif (!verifyCaptcha($_POST['captcha'] ?? '')) {
    $errorMessage = 'The captcha code is incorrect. Please try again.';
    
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
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Create account | FindIT');
fiPublicNav(null, 'register');
?>

<main class="fi-auth" id="main">
    <div class="fi-auth-card fi-auth-card--wide">

        <div class="fi-auth-head">
            <a href="index.php" class="fi-brand">FindIT<span>.</span></a>
            <p>Create your student account to get started.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success fi-alert" role="status">
                <span class="fi-alert-dot" aria-hidden="true"></span>
                <div class="fi-alert-text">
                    <?= fiE($successMessage) ?>
                    <a href="login.php" class="alert-link">Sign in here.</a>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php" class="needs-validation" novalidate>
            <input
                type="hidden"
                name="csrf_token"
                value="<?= fiE($csrfToken) ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label for="name" class="form-label">Full name</label>
                    <input
                        type="text"
                        class="form-control"
                        id="name"
                        name="name"
                        maxlength="50"
                        value="<?= fiE($formData['name']) ?>"
                        placeholder="Enter your full name"
                        autocomplete="name"
                        required>
                    <div class="invalid-feedback">Please enter your full name.</div>
                </div>

                <div class="col-12">
                    <label for="email" class="form-label">Campus email</label>
                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        maxlength="100"
                        value="<?= fiE($formData['email']) ?>"
                        placeholder="123456789012@student.polinema.ac.id"
                        pattern="[0-9]{12}@student\.polinema\.ac\.id"
                        data-nim-source
                        title="Use your student email: 12-digit NIM@student.polinema.ac.id"
                        autocomplete="email"
                        required>
                    <div class="form-text">
                        Only Polinema student emails work: NIM@student.polinema.ac.id
                    </div>
                    <div class="invalid-feedback">
                        Please use your student email in the format NIM@student.polinema.ac.id.
                    </div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="phone" class="form-label">Phone number</label>
                    <input
                        type="tel"
                        class="form-control"
                        id="phone"
                        name="phone"
                        maxlength="15"
                        value="<?= fiE($formData['phone']) ?>"
                        placeholder="08xxxxxxxxxx"
                        autocomplete="tel"
                        required>
                    <div class="invalid-feedback">Please enter your phone number.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="student_id" class="form-label">Student ID (NIM)</label>
                    <input
                        type="text"
                        class="form-control"
                        id="student_id"
                        name="student_id"
                        maxlength="12"
                        inputmode="numeric"
                        pattern="[0-9]{12}"
                        data-nim-email="#email"
                        title="12 digits, and must be the same as the NIM in your campus email"
                        value="<?= fiE($formData['student_id']) ?>"
                        placeholder="12-digit NIM"
                        autocomplete="off"
                        required>
                    <div class="invalid-feedback" data-nim-feedback>Enter your 12-digit NIM.</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="password" class="form-label">Password</label>
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
                        <div class="invalid-feedback">Use at least 8 characters.</div>
                    </div>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="confirm_password" class="form-label">Confirm password</label>
                    <div class="input-group has-validation">
                        <input
                            type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="8"
                            data-match="#password"
                            autocomplete="new-password"
                            placeholder="Repeat your password"
                            required>
                        <button
                            type="button"
                            class="btn fi-pass-toggle"
                            data-toggle-password="#confirm_password"
                            aria-label="Show or hide confirm password">Show</button>
                        <div class="invalid-feedback">The passwords do not match.</div>
                    </div>
                </div>
            </div>

                <?php // Captcha: draw code, refresh button, and answer column?>
            <div class="mt-3">
                <label for="captcha" class="form-label">Verify that you are not a bot</label>

                <div class="d-flex align-items-center gap-2 mb-2">
                    <?php // browser ask iage to captcha.php ?>
                    <img
                        id="captcha-image"
                        src="captcha.php"
                        alt="Captcha code"
                        width="200"
                        height="60"
                        style="border: 1px solid #ddd; border-radius: 8px;">

                    <?php ?>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        onclick="document.getElementById('captcha-image').src = 'captcha.php?t=' + Date.now();">
                        Refresh
                    </button>
                </div>

                <input
                    type="text"
                    class="form-control"
                    id="captcha"
                    name="captcha"
                    maxlength="5"
                    autocomplete="off"
                    placeholder="Type the characters above"
                    required>
            </div>
            <button
                type="submit"
                class="btn btn-primary w-100 mt-3"
                data-loading-text="Creating account...">
                Create account
            </button>
        </form>

        <p class="fi-auth-foot">
            Already have an account?
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
