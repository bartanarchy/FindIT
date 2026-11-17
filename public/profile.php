<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/avatar.php';
require_once dirname(__DIR__) . '/app/models/User.php';

// Only signed-in students have a profile page.
requireRole('student');

$sessionUser = currentUser();
$userId = (int) ($sessionUser['id'] ?? 0);
$userModel = new User($pdo);

/** Remember a message across the redirect (so a refresh never re-submits a form). */
function profileFlash(string $section, string $type, string $message): void
{
    $_SESSION['profile_flash'] = ['section' => $section, 'type' => $type, 'message' => $message];
    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $section = in_array($action, ['change_password'], true) ? 'password' : 'photo';

    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        profileFlash($section, 'danger', 'Your session has expired. Please refresh the page and try again.');
    }

    try {
        switch ($action) {
            case 'upload_photo':
                $error = FiAvatar::savePhoto($pdo, $userId, $_FILES['photo'] ?? []);
                $error === null
                    ? profileFlash('photo', 'success', 'Your profile photo has been updated.')
                    : profileFlash('photo', 'danger', $error);
                break;

            case 'choose_avatar':
                FiAvatar::setPreset($pdo, $userId, (string) ($_POST['avatar'] ?? ''))
                    ? profileFlash('photo', 'success', 'Your avatar has been updated.')
                    : profileFlash('photo', 'danger', 'Please choose one of the avatars.');
                break;

            case 'remove_photo':
                FiAvatar::remove($pdo, $userId);
                profileFlash('photo', 'success', 'Your profile picture has been removed.');
                break;

            case 'change_password':
                $current = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                $account = $userModel->findById($userId);

                if ($account === null) {
                    profileFlash('password', 'danger', 'Your account could not be found.');
                }
                if ($current === '' || $new === '' || $confirm === '') {
                    profileFlash('password', 'danger', 'Please complete all password fields.');
                }
                if (!password_verify($current, (string) $account['password_user'])) {
                    profileFlash('password', 'danger', 'Your current password is incorrect.');
                }
                if (strlen($new) < 8) {
                    profileFlash('password', 'danger', 'Your new password must contain at least 8 characters.');
                }
                if (strlen($new) > 72) {
                    profileFlash('password', 'danger', 'Your new password must not be longer than 72 characters.');
                }
                if ($new !== $confirm) {
                    profileFlash('password', 'danger', 'The new passwords do not match.');
                }
                if (hash_equals($current, $new)) {
                    profileFlash('password', 'danger', 'Your new password must be different from the current one.');
                }

                $userModel->updatePassword($userId, password_hash($new, PASSWORD_DEFAULT));
                session_regenerate_id(true);
                profileFlash('password', 'success', 'Your password has been changed.');
                break;

            default:
                profileFlash($section, 'danger', 'Unknown action.');
        }
    } catch (PDOException $e) {
        error_log('[FindIT] Profile update failed: ' . $e->getMessage());
        profileFlash($section, 'danger', 'Your changes could not be saved. Please try again.');
    }
}

// Message left by the previous request (if any).
$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

$account = null;
$stats = ['reports' => 0, 'claims' => 0, 'returned' => 0];
$loadError = '';

try {
    $account = $userModel->findById($userId);
    $stats = $userModel->profileStats($userId);
} catch (PDOException $e) {
    error_log('[FindIT] Failed to load the profile: ' . $e->getMessage());
    $loadError = 'Unable to load your profile. Please try again later.';
}

$avatarInfo = FiAvatar::info($pdo, $userId);
$csrfToken = generateCsrfToken();
$flashFor = static function (string $section) use ($flash): ?array {
    return ($flash && ($flash['section'] ?? '') === $section) ? $flash : null;
};

fiHead('My profile | FindIT');
fiPublicNav($sessionUser, 'profile');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow" style="width: min(760px, 100%);">

            <?php if ($loadError !== '' || $account === null): ?>
                <?php fiAlert('danger', $loadError !== '' ? $loadError : 'Your account could not be found.'); ?>
            <?php else: ?>

            <!-- Profile header -->
            <section class="fi-profile-head" aria-labelledby="profileTitle">
                <button type="button"
                        class="fi-avatar-zoom-btn"
                        data-avatar-zoom
                        aria-label="View profile photo larger"
                        title="Click to zoom">
                    <?= FiAvatar::render($avatarInfo, 84, 'avatar.php', 'fi-avatar--xl') ?>
                    <span class="fi-avatar-zoom-badge" aria-hidden="true">Zoom</span>
                </button>
                <div class="fi-profile-id">
                    <h1 class="fi-profile-name" id="profileTitle"><?= fiE($account['name_user']) ?></h1>
                    <p class="fi-profile-sub">Student &middot; ID <?= fiE($account['id_nim']) ?></p>
                    <div class="fi-profile-badges">
                        <span class="fi-badge fi-badge--info">Student account</span>
                        <span class="fi-badge fi-badge--success">Verified</span>
                    </div>
                </div>
            </section>

            <!-- Account overview -->
            <section class="fi-profile-card" aria-labelledby="overviewTitle">
                <h2 class="fi-profile-h" id="overviewTitle">Account overview</h2>
                <div class="fi-profile-stats">
                    <a class="fi-profile-stat" href="report-item.php">
                        <span class="fi-profile-stat-num"><?= number_format($stats['reports']) ?></span>
                        <span class="fi-profile-stat-label">My reports</span>
                    </a>
                    <a class="fi-profile-stat" href="my-claims.php">
                        <span class="fi-profile-stat-num"><?= number_format($stats['claims']) ?></span>
                        <span class="fi-profile-stat-label">My claims</span>
                    </a>
                    <div class="fi-profile-stat">
                        <span class="fi-profile-stat-num fi-profile-stat-num--ok"><?= number_format($stats['returned']) ?></span>
                        <span class="fi-profile-stat-label">Returned</span>
                    </div>
                </div>
            </section>

            <!-- Personal information -->
            <section class="fi-profile-card" aria-labelledby="infoTitle">
                <h2 class="fi-profile-h" id="infoTitle">Personal information</h2>
                <dl class="fi-profile-info">
                    <div><dt>Full name</dt><dd><?= fiE($account['name_user']) ?></dd></div>
                    <div><dt>Student ID</dt><dd><?= fiE($account['id_nim']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= fiE($account['email_kampus']) ?></dd></div>
                </dl>
            </section>

            <!-- Profile photo -->
            <section class="fi-profile-card" aria-labelledby="photoTitle" id="photo">
                <h2 class="fi-profile-h" id="photoTitle">Profile photo</h2>

                <?php if ($f = $flashFor('photo')): ?>
                    <?php fiAlert($f['type'], $f['message'], $f['type'] === 'success'); ?>
                <?php endif; ?>

                <form method="POST" action="profile.php" enctype="multipart/form-data"
                      class="fi-profile-upload needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">
                    <input type="hidden" name="action" value="upload_photo">

                    <div class="fi-profile-preview" data-photo-preview aria-hidden="true">
                        <?= FiAvatar::render($avatarInfo, 72, 'avatar.php') ?>
                    </div>

                    <div class="fi-profile-upload-body">
                        <label for="photo-file" class="form-label">Add a photo</label>
                        <input type="file" class="form-control" id="photo-file" name="photo"
                               accept="image/jpeg,image/png,image/webp" data-photo-input required>
                        <div class="form-text">JPG, PNG or WebP, up to 2 MB. It is cropped to a square.</div>
                        <div class="invalid-feedback">Please choose a photo.</div>
                        <button type="submit" class="btn btn-primary btn-sm mt-3"
                                data-loading-text="Uploading...">Upload photo</button>
                    </div>
                </form>

                <hr class="fi-profile-rule">

                <form method="POST" action="profile.php" class="needs-validation" novalidate data-avatar-form>
                    <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">
                    <input type="hidden" name="action" value="choose_avatar">

                    <fieldset class="fi-avatar-picker">
                        <legend class="form-label">Or choose an avatar</legend>
                        <div class="fi-avatar-grid">
                            <?php foreach (FiAvatar::PRESETS as $key => $preset): ?>
                                <?php $checked = ($avatarInfo['type'] === 'preset' && $avatarInfo['preset'] === $key); ?>
                                <label class="fi-avatar-option">
                                    <input type="radio" name="avatar" value="<?= fiE($key) ?>"
                                           <?= $checked ? 'checked' : '' ?> required>
                                    <span class="fi-avatar-option-face"><?= FiAvatar::presetSvg($key, 64) ?></span>
                                    <span class="visually-hidden"><?= fiE($preset[0]) ?> avatar</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary btn-sm mt-3">Use this avatar</button>
                </form>

                <?php if ($avatarInfo['type'] !== 'none'): ?>
                    <form method="POST" action="profile.php" class="mt-3 needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">
                        <input type="hidden" name="action" value="remove_photo">
                        <button type="submit" class="btn btn-outline-secondary btn-sm"
                                data-confirm="Remove your profile picture? The default icon will be shown instead."
                                data-confirm-label="Yes, remove it">Remove picture</button>
                    </form>
                <?php endif; ?>
            </section>

            <!-- Change password -->
            <section class="fi-profile-card" aria-labelledby="passwordTitle" id="password">
                <h2 class="fi-profile-h" id="passwordTitle">Change password</h2>

                <?php if ($f = $flashFor('password')): ?>
                    <?php fiAlert($f['type'], $f['message'], $f['type'] === 'success'); ?>
                <?php endif; ?>

                <form method="POST" action="profile.php" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="input-group has-validation">
                            <input type="password" class="form-control" id="current_password"
                                   name="current_password" autocomplete="current-password" required>
                            <button type="button" class="btn fi-pass-toggle"
                                    data-toggle-password="#current_password"
                                    aria-label="Show or hide current password">Show</button>
                            <div class="invalid-feedback">Please enter your current password.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New password</label>
                        <div class="input-group has-validation">
                            <input type="password" class="form-control" id="new_password"
                                   name="new_password" minlength="8" maxlength="72"
                                   autocomplete="new-password" placeholder="At least 8 characters" required>
                            <button type="button" class="btn fi-pass-toggle"
                                    data-toggle-password="#new_password"
                                    aria-label="Show or hide new password">Show</button>
                            <div class="invalid-feedback">Password must be at least 8 characters.</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">Confirm new password</label>
                        <div class="input-group has-validation">
                            <input type="password" class="form-control" id="confirm_password"
                                   name="confirm_password" minlength="8" maxlength="72"
                                   autocomplete="new-password" data-match="#new_password"
                                   placeholder="Re-enter your new password" required>
                            <button type="button" class="btn fi-pass-toggle"
                                    data-toggle-password="#confirm_password"
                                    aria-label="Show or hide confirm password">Show</button>
                            <div class="invalid-feedback">The passwords must match.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"
                            data-loading-text="Updating password...">Update password</button>
                </form>
            </section>

            <?php endif; ?>
        </div>
    </div>

    <div class="fi-lightbox" id="fiAvatarLightbox" role="dialog" aria-modal="true" aria-labelledby="fiLightboxTitle" hidden>
        <div class="fi-lightbox-backdrop" data-lightbox-close></div>
        <div class="fi-lightbox-panel">
            <div class="fi-lightbox-head">
                <span class="fi-lightbox-title" id="fiLightboxTitle">Profile photo</span>
                <div class="fi-lightbox-actions">
                    <button type="button" class="btn btn-sm btn-outline-light" data-zoom-out aria-label="Zoom out">&minus;</button>
                    <span class="fi-lightbox-level" data-zoom-level>100%</span>
                    <button type="button" class="btn btn-sm btn-outline-light" data-zoom-in aria-label="Zoom in">+</button>
                    <button type="button" class="btn btn-sm btn-outline-light" data-zoom-reset>Reset</button>
                    <button type="button" class="btn btn-sm btn-light" data-lightbox-close aria-label="Close">&times;</button>
                </div>
            </div>
            <div class="fi-lightbox-stage" data-lightbox-stage></div>
        </div>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>