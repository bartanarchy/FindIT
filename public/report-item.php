<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/item-photo.php';
require_once dirname(__DIR__) . '/app/helpers/verification.php';

// only logged-in students can report found items.
requireRole('student');
// An unverified student is sent to the profile page right away 
requireVerifiedStudent($pdo);

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
    // Check the CSRF token before processing the submitted form.
    $submittedToken = $_POST['csrf_token'] ?? '';
    $submittedToken = is_string($submittedToken)
        ? $submittedToken
        : '';

    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage =
            'Your form session has expired. Please refresh the page and try again.';
    } else {
        // Read the submitted form values.
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

        // Validate the required fields and input lengths.
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
        } elseif ($dateFound > date('Y-m-d')) {
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
            // Validate the uploaded photo.
            try {
                $photoData = processItemPhotoUpload(
                    $_FILES['item_photo'] ?? null
                );
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }

            if ($errorMessage === '') {
                // Make sure the selected category exists.
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
                    // Save the report under the student who is signed in.
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO public.item (
                            id_user_pelapor,
                            id_kategori,
                            nama_barang,
                            detail_lokasi,
                            tanggal_ditemukan,
                            ciri_umum,
                            ciri_khusus,
                            photo_url,
                            status_barang
                        ) VALUES (
                            :reporter_id,
                            :category_id,
                            :name,
                            :location,
                            :date_found,
                            :general_features,
                            :distinctive_features,
                            :photo_url,
                            :status
                        )
                        RETURNING kode_laporan'
                    );

                    $insertStmt->bindValue(
                        ':reporter_id',
                        $userId,
                        PDO::PARAM_INT
                    );
                    $insertStmt->bindValue(
                        ':category_id',
                        $categoryId,
                        PDO::PARAM_INT
                    );
                    $insertStmt->bindValue(
                        ':name',
                        Crypto::protect('nama_barang', $name)
                    );
                    $insertStmt->bindValue(
                        ':location',
                        Crypto::protect('detail_lokasi', $location)
                    );
                    $insertStmt->bindValue(
                        ':date_found',
                        $dateFound
                    );
                    $insertStmt->bindValue(
                        ':general_features',
                        $generalFeatures !== ''
                            ? Crypto::protect('ciri_umum', $generalFeatures)
                            : null
                    );
                    $insertStmt->bindValue(
                        ':distinctive_features',
                        Crypto::protect('ciri_khusus', $distinctiveFeatures)
                    );

                    if ($photoData === null) {
                        $insertStmt->bindValue(
                            ':photo_url',
                            null,
                            PDO::PARAM_NULL
                        );
                    } else {
                        $insertStmt->bindValue(
                            ':photo_url',
                            $photoData,
                            PDO::PARAM_LOB
                        );
                    }

                    $insertStmt->bindValue(
                        ':status',
                        'Found'
                    );

                    $insertStmt->execute();

                    // Redirect after saving to avoid duplicate submissions.
                    $_SESSION['home_flash'] = 'Your found item report has been submitted successfully.';
                    header('Location: index.php');
                    exit;
                }
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

            <form method="POST" action="report-item.php" class="fi-form-card needs-validation" enctype="multipart/form-data" novalidate>
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
                            <label for="item_photo" class="form-label">Item photo</label>
                            <input
                                type="file"
                                class="form-control"
                                id="item_photo"
                                name="item_photo"
                                accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">
                                Upload a clear photo of the found item. JPG, PNG, or WEBP. After choosing a photo you can move and zoom it to crop.
                            </div>
                            <div class="invalid-feedback">
                                Please select a valid image file.
                            </div>
                            <div id="photo-preview-container" class="mt-3 d-none">
                                <img
                                    id="photo-preview"
                                    src=""
                                    alt="Selected item photo preview"
                                    class="img-fluid rounded"
                                    style="max-height: 240px; object-fit: contain;">
                                <div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="photo-adjust">Adjust photo</button>
                                </div>
                            </div>
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
<?php // Crop dialog: opens after a photo is chosen so it can be moved and zoomed first. ?>
<div class="fi-lightbox fi-cropper fi-cropper-rect" id="fiItemCropper" role="dialog" aria-modal="true"
     aria-labelledby="itemCropTitle" hidden>
    <div class="fi-lightbox-backdrop" data-item-crop-cancel></div>
    <div class="fi-crop-card">
        <h2 class="fi-crop-title" id="itemCropTitle">Crop your photo</h2>
        <p class="fi-crop-help">Drag the photo to move it. Use the slider or the mouse wheel to zoom.</p>
        <div class="fi-crop-viewport" data-item-crop-viewport tabindex="0"
             aria-label="Photo position. Use the arrow keys to move and plus or minus to zoom.">
            <img alt="" draggable="false" data-item-crop-image>
            <span class="fi-crop-mask" aria-hidden="true"></span>
            <span class="fi-crop-grid" aria-hidden="true"></span>
        </div>
        <label class="fi-crop-zoom">
            <span>Zoom</span>
            <input type="range" min="1" max="4" step="0.01" value="1" data-item-crop-zoom aria-label="Zoom">
        </label>
        <div class="fi-crop-actions">
            <button type="button" class="btn btn-outline-secondary" data-item-crop-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-item-crop-apply>Use this photo</button>
        </div>
    </div>
</div>
<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>

</html>