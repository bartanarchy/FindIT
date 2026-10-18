
<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// Only admins can edit item reports.
requireRole('admin');

// Escape values before displaying them in HTML.
function escapeEditItemHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    header('Location: items.php');
    exit;
}

$errorMessage = '';
$successMessage = '';
$item = null;
$categories = [];

try {
    // Load all available categories for the edit form.
    $categoryStmt = $pdo->query(
        'SELECT id_category, name_category
         FROM public.category
         ORDER BY name_category ASC'
    );

    $categories = $categoryStmt->fetchAll();

    // Find the report that the admin wants to edit.
    $itemStmt = $pdo->prepare(
        'SELECT
            kode_laporan,
            nama_barang,
            id_kategori,
            detail_lokasi,
            tanggal_ditemukan,
            ciri_umum,
            ciri_khusus
         FROM public.item
         WHERE kode_laporan = :item_id
         LIMIT 1'
    );

    $itemStmt->execute(['item_id' => $itemId]);
    $item = $itemStmt->fetch();

    if (!$item) {
        http_response_code(404);
        exit('The requested item report was not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $submittedToken = is_string($submittedToken)
            ? $submittedToken
            : '';

        if (!verifyCsrfToken($submittedToken)) {
            $errorMessage =
                'Your form session has expired. Please refresh the page and try again.';
        } else {
            // Read the submitted form values.
            $name = trim((string) ($_POST['nama_barang'] ?? ''));
            $categoryId = filter_var(
                $_POST['id_kategori'] ?? null,
                FILTER_VALIDATE_INT
            );
            $location = trim((string) ($_POST['detail_lokasi'] ?? ''));
            $dateFound = trim((string) ($_POST['tanggal_ditemukan'] ?? ''));
            $generalFeatures = trim((string) ($_POST['ciri_umum'] ?? ''));
            $distinctiveFeatures = trim((string) ($_POST['ciri_khusus'] ?? ''));

            // Keep the submitted values visible if validation fails.
            $item['nama_barang'] = $name;
            $item['id_kategori'] = $categoryId ?: null;
            $item['detail_lokasi'] = $location;
            $item['tanggal_ditemukan'] = $dateFound;
            $item['ciri_umum'] = $generalFeatures;
            $item['ciri_khusus'] = $distinctiveFeatures;

            // Validate the form before updating the database.
            if ($name === '' || mb_strlen($name) > 100) {
                $errorMessage =
                    'The item name is required and must not exceed 100 characters.';
            } elseif (!$categoryId || $categoryId < 1) {
                $errorMessage = 'Please select a valid category.';
            } elseif ($location === '') {
                $errorMessage = 'Please enter the item location.';
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
            } else {
                // Check that the selected category exists.
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
                    // Save the updated item details.
                    $updateStmt = $pdo->prepare(
                        'UPDATE public.item
                         SET
                            nama_barang = :name,
                            id_kategori = :category_id,
                            detail_lokasi = :location,
                            tanggal_ditemukan = :date_found,
                            ciri_umum = :general_features,
                            ciri_khusus = :distinctive_features
                         WHERE kode_laporan = :item_id'
                    );

                    $updateStmt->execute([
                        'name' => $name,
                        'category_id' => $categoryId,
                        'location' => $location,
                        'date_found' => $dateFound,
                        'general_features' => $generalFeatures !== ''
                            ? $generalFeatures
                            : null,
                        'distinctive_features' => $distinctiveFeatures !== ''
                            ? $distinctiveFeatures
                            : null,
                        'item_id' => $itemId
                    ]);

                    // Return to the inventory after saving.
                    header('Location: items.php?updated=1');
                    exit;
                }
            }
        }
    }
} catch (PDOException $e) {
    // Keep technical database details in the server log.
    error_log('Edit Item error: ' . $e->getMessage());
    $errorMessage = 'Unable to process this item report. Please try again later.';
}

?>

<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Edit item report | FindIT Admin', '../public/assets');
fiAdminNav('items');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow">

            <a href="items.php" class="fi-back-link">&larr; Back to items</a>

            <?php
            fiPageHeader(
                'Campus lost & found / Inventory',
                'Edit item<br><span>report.</span>',
                'Update the item information and keep the report accurate.',
                '#' . (int) $itemId,
                'Report ID'
            );
            ?>

            <?php if ($errorMessage !== ''): ?>
                <?php fiAlert('danger', $errorMessage); ?>
            <?php endif; ?>

            <form
                method="POST"
                action="edit-item.php?id=<?= (int) $itemId ?>"
                class="fi-form-card needs-validation"
                novalidate>
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
                                value="<?= fiE($item['nama_barang'] ?? '') ?>"
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
                                        <?= (string) ($item['id_kategori'] ?? '') === (string) $category['id_category']
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
                                required><?= fiE($item['detail_lokasi'] ?? '') ?></textarea>
                            <div class="invalid-feedback">Please tell us where the item was found.</div>
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
                                value="<?= fiE($item['tanggal_ditemukan'] ?? '') ?>"
                                required>
                            <div class="invalid-feedback">Please choose the date found.</div>
                        </div>

                        <div class="col-12">
                            <label for="ciri_umum" class="form-label">General features</label>
                            <textarea
                                class="form-control"
                                id="ciri_umum"
                                name="ciri_umum"
                                rows="3"><?= fiE($item['ciri_umum'] ?? '') ?></textarea>
                        </div>
                    </div>
                </section>

                <section class="fi-form-section">
                    <h2 class="fi-form-section-title">Verification details</h2>
                    <p class="fi-form-section-hint">
                        Private. Used by admins to compare against claim evidence.
                    </p>

                    <label for="ciri_khusus" class="form-label">Distinctive features</label>
                    <textarea
                        class="form-control"
                        id="ciri_khusus"
                        name="ciri_khusus"
                        rows="3"><?= fiE($item['ciri_khusus'] ?? '') ?></textarea>
                </section>

                <div class="fi-form-actions">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        data-loading-text="Saving...">
                        Save changes
                    </button>
                    <a href="items.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>

        </div>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
