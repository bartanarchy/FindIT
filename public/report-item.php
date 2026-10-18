<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// only logged-in students can report found items.
requireRole('student');

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);

function escapeReportItemHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$errorMessage = '';
$categories = [];

$formData = [
    'nama_barang' => '',
    'id_kategori' => '',
    'detail_lokasi' => '',
    'tanggal_ditemukan' => date('Y-m-d'),
    'ciri_umum' => '',
    'ciri_khusus' => ''
];

try {
    // load categories for the report form.
    $categoryStmt = $pdo->query(
        'SELECT id_category, name_category
         FROM public.category
         ORDER BY name_category ASC'
    );

    $categories = $categoryStmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // check the CSRF token before processing the submitted form.
        $submittedToken = $_POST['csrf_token'] ?? '';
        $submittedToken = is_string($submittedToken)
            ? $submittedToken
            : '';

        if (!verifyCsrfToken($submittedToken)) {
            $errorMessage =
                'Your form session has expired. Please refresh the page and try again.';
        } else {
            // read the submitted form values.
            foreach ($formData as $field => $defaultValue) {
                $submittedValue = $_POST[$field] ?? $defaultValue;

                $formData[$field] = is_string($submittedValue)
                    ? trim($submittedValue)
                    : $defaultValue;
            }

            $name = $formData['nama_barang'];
            $categoryId = filter_var(
                $formData['id_kategori'],
                FILTER_VALIDATE_INT
            );
            $location = $formData['detail_lokasi'];
            $dateFound = $formData['tanggal_ditemukan'];
            $generalFeatures = $formData['ciri_umum'];
            $distinctiveFeatures = $formData['ciri_khusus'];

            // validate the required fields and input lengths.
            if ($userId < 1) {
                $errorMessage =
                    'Unable to identify your student account. Please sign in again.';
            } elseif ($name === '' || mb_strlen($name) > 100) {
                $errorMessage =
                    'The item name is required and must not exceed 100 characters.';
            } elseif (!$categoryId || $categoryId < 1) {
                $errorMessage = 'Please select a valid category.';
            } elseif ($location === '') {
                $errorMessage = 'Please enter the location where the item was found.';
            } elseif (mb_strlen($location) > 5000) {
                $errorMessage = 'The location must not exceed 5,000 characters.';
            } elseif ($dateFound === '') {
                $errorMessage = 'Please enter the date the item was found.';
            } elseif (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFound) ||
                !checkdate(
                    (int) substr($dateFound, 5, 2),
                    (int) substr($dateFound, 8, 2),
                    (int) substr($dateFound, 0, 4)
                )
            ) {
                $errorMessage = 'Please enter a valid date.';
            } elseif (
                $dateFound > date('Y-m-d')
            ) {
                $errorMessage = 'The date found cannot be in the future.';
            } elseif (
                mb_strlen($generalFeatures) > 5000 ||
                mb_strlen($distinctiveFeatures) > 5000
            ) {
                $errorMessage =
                    'Each description must not exceed 5,000 characters.';
            } elseif ($distinctiveFeatures === '') {
                $errorMessage =
                    'Please enter the distinctive features. This information will be kept private.';
            } else {
                // make sure the selected category exists.
                $checkCategory = $pdo->prepare(
                    'SELECT id_category
                     FROM public.category
                     WHERE id_category = :category_id'
                );

                $checkCategory->execute([
                    'category_id' => $categoryId
                ]);

                if (!$checkCategory->fetch()) {
                    $errorMessage = 'The selected category does not exist.';
                } else {
                    // save the report under the student who is signed in.
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO public.item (
                            id_user_pelapor,
                            id_kategori,
                            nama_barang,
                            detail_lokasi,
                            tanggal_ditemukan,
                            ciri_umum,
                            ciri_khusus,
                            status_barang
                        ) VALUES (
                            :reporter_id,
                            :category_id,
                            :name,
                            :location,
                            :date_found,
                            :general_features,
                            :distinctive_features,
                            :status
                        )
                        RETURNING kode_laporan'
                    );

                    $insertStmt->execute([
                        'reporter_id' => $userId,
                        'category_id' => $categoryId,
                        'name' => $name,
                        'location' => $location,
                        'date_found' => $dateFound,
                        'general_features' => $generalFeatures !== ''
                            ? $generalFeatures
                            : null,
                        'distinctive_features' => $distinctiveFeatures,
                        'status' => 'Ditemukan'
                    ]);

                    // redirect after saving to avoid duplicate submissions.
                    header('Location: report-item.php?created=1');
                    exit;
                }
            }
        }
    }
} catch (PDOException $e) {
    // keep technical database details in the server log.
    error_log('Student Report Item error: ' . $e->getMessage());
    $errorMessage = 'Unable to submit your item report. Please try again later.';
}

$successMessage = (
    isset($_GET['created']) &&
    $_GET['created'] === '1'
)
    ? 'Your found item report has been submitted successfully.'
    : '';

?>

<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Report found item | FindIT');
fiPublicNav(currentUser(), 'report');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow">

            <a href="index.php" class="fi-back-link">&larr; Back to found items</a>

            <?php
            fiPageHeader(
                'Campus lost & found / Report item',
                'Report a<br><span>found item.</span>',
                'Share the general details so the owner can recognize the item. '
                . 'Keep distinctive features private for verification.'
            );
            ?>

            <?php if ($errorMessage !== ''): ?>
                <?php fiAlert('danger', $errorMessage); ?>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <?php fiAlert('success', $successMessage, true); ?>
            <?php endif; ?>

            <form method="POST" action="report-item.php" class="fi-form-card needs-validation" novalidate>
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= fiE(generateCsrfToken()) ?>">

                <section class="fi-form-section">
                    <h2 class="fi-form-section-title">Item details</h2>
                    <p class="fi-form-section-hint">
                        This information is shown publicly on the item listing.
                    </p>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label for="nama_barang" class="form-label">
                                Item name<span class="fi-required" aria-hidden="true">*</span>
                            </label>
                            <input
                                type="text"
                                class="form-control"
                                id="nama_barang"
                                name="nama_barang"
                                maxlength="100"
                                placeholder="Example: Black wireless headphones"
                                value="<?= fiE($formData['nama_barang']) ?>"
                                required>
                            <div class="invalid-feedback">Please enter the item name.</div>
                        </div>

                        <div class="col-12 col-md-5">
                            <label for="id_kategori" class="form-label">
                                Category<span class="fi-required" aria-hidden="true">*</span>
                            </label>
                            <select
                                class="form-select"
                                id="id_kategori"
                                name="id_kategori"
                                required>
                                <option value="">Select a category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option
                                        value="<?= (int) $category['id_category'] ?>"
                                        <?= (string) $formData['id_kategori'] === (string) $category['id_category']
                                            ? 'selected'
                                            : '' ?>>
                                        <?= fiE($category['name_category']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please choose a category.</div>
                        </div>

                        <div class="col-12 col-md-8">
                            <label for="detail_lokasi" class="form-label">
                                Location found<span class="fi-required" aria-hidden="true">*</span>
                            </label>
                            <textarea
                                class="form-control"
                                id="detail_lokasi"
                                name="detail_lokasi"
                                rows="2"
                                maxlength="5000"
                                placeholder="Example: IT Building, 5th floor corridor"
                                required><?= fiE($formData['detail_lokasi']) ?></textarea>
                            <div class="invalid-feedback">Please tell us where you found the item.</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="tanggal_ditemukan" class="form-label">
                                Date found<span class="fi-required" aria-hidden="true">*</span>
                            </label>
                            <input
                                type="date"
                                class="form-control"
                                id="tanggal_ditemukan"
                                name="tanggal_ditemukan"
                                max="<?= date('Y-m-d') ?>"
                                value="<?= fiE($formData['tanggal_ditemukan']) ?>"
                                required>
                            <div class="invalid-feedback">Choose a date that is not in the future.</div>
                        </div>

                        <div class="col-12">
                            <label for="ciri_umum" class="form-label">General features</label>
                            <textarea
                                class="form-control"
                                id="ciri_umum"
                                name="ciri_umum"
                                rows="3"
                                maxlength="5000"
                                data-counter
                                placeholder="Describe the item's visible appearance, such as color and brand."><?= fiE($formData['ciri_umum']) ?></textarea>
                        </div>
                    </div>
                </section>

                <section class="fi-form-section">
                    <h2 class="fi-form-section-title">Verification details</h2>
                    <p class="fi-form-section-hint">
                        Only the admin can see this. It is used to verify ownership claims.
                    </p>

                    <label for="ciri_khusus" class="form-label">
                        Distinctive features (private)<span class="fi-required" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        class="form-control"
                        id="ciri_khusus"
                        name="ciri_khusus"
                        rows="3"
                        maxlength="5000"
                        data-counter
                        placeholder="Enter details only the finder and admin should know."
                        required><?= fiE($formData['ciri_khusus']) ?></textarea>
                    <div class="invalid-feedback">
                        Please add at least one distinctive feature for verification.
                    </div>

                    <div class="fi-callout mt-3">
                        This information will not be shown on the public item listing.
                        The admin will use it to help verify ownership claims.
                    </div>
                </section>

                <div class="fi-form-actions">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        data-loading-text="Submitting...">
                        Submit report
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>

        </div>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
