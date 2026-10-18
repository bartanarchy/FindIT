
<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// Only admins can create item reports.
requireRole('admin');

// Escape values before displaying them in HTML.
function escapeAddItemHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$errorMessage = '';
$categories = [];
$students = [];

$formData = [
    'nama_barang' => '',
    'id_kategori' => '',
    'id_user_pelapor' => '',
    'detail_lokasi' => '',
    'tanggal_ditemukan' => date('Y-m-d'),
    'ciri_umum' => '',
    'ciri_khusus' => ''
];

try {
    // Load categories for the category dropdown.
    $categoryStmt = $pdo->query(
        'SELECT id_category, name_category
         FROM public.category
         ORDER BY name_category ASC'
    );

    $categories = $categoryStmt->fetchAll();

    // Load student accounts to associate each report with a valid reporter.
    $studentStmt = $pdo->query(
        'SELECT id_user, name_user, email_kampus
         FROM public."User"
         WHERE role_user = \'student\'
         ORDER BY name_user ASC'
    );

    $students = $studentStmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Read and validate the submitted CSRF token.
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
            $reporterId = filter_var(
                $formData['id_user_pelapor'],
                FILTER_VALIDATE_INT
            );
            $location = $formData['detail_lokasi'];
            $dateFound = $formData['tanggal_ditemukan'];
            $generalFeatures = $formData['ciri_umum'];
            $distinctiveFeatures = $formData['ciri_khusus'];

            // Validate required fields and input lengths.
            if ($name === '' || mb_strlen($name) > 100) {
                $errorMessage =
                    'The item name is required and must not exceed 100 characters.';
            } elseif (!$categoryId || $categoryId < 1) {
                $errorMessage = 'Please select a valid category.';
            } elseif (!$reporterId || $reporterId < 1) {
                $errorMessage = 'Please select a valid student reporter.';
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
                mb_strlen($generalFeatures) > 5000 ||
                mb_strlen($distinctiveFeatures) > 5000
            ) {
                $errorMessage =
                    'Each item description must not exceed 5,000 characters.';
            } else {
                // Make sure the selected category and reporter exist.
                $checkStmt = $pdo->prepare(
                    'SELECT
                        EXISTS (
                            SELECT 1
                            FROM public.category
                            WHERE id_category = :category_id
                        ) AS category_exists,
                        EXISTS (
                            SELECT 1
                            FROM public."User"
                            WHERE id_user = :reporter_id
                              AND role_user = \'student\'
                        ) AS reporter_exists'
                );

                $checkStmt->execute([
                    'category_id' => $categoryId,
                    'reporter_id' => $reporterId
                ]);

                $checkResult = $checkStmt->fetch();

                if (
                    !$checkResult ||
                    !in_array(
                        $checkResult['category_exists'],
                        [true, 1, '1', 't', 'true'],
                        true
                    )
                ) {
                    $errorMessage = 'The selected category does not exist.';
                } elseif (
                    !in_array(
                        $checkResult['reporter_exists'],
                        [true, 1, '1', 't', 'true'],
                        true
                    )
                ) {
                    $errorMessage = 'The selected student account does not exist.';
                } else {
                    // Create the report with the default found status.
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
                        'reporter_id' => $reporterId,
                        'category_id' => $categoryId,
                        'name' => $name,
                        'location' => $location,
                        'date_found' => $dateFound,
                        'general_features' => $generalFeatures !== ''
                            ? $generalFeatures
                            : null,
                        'distinctive_features' => $distinctiveFeatures !== ''
                            ? $distinctiveFeatures
                            : null,
                        'status' => 'Ditemukan'
                    ]);

                    // Return to the inventory after creating the report.
                    header('Location: items.php?created=1');
                    exit;
                }
            }
        }
    }
} catch (PDOException $e) {
    // Keep technical database details in the server log.
    error_log('Add Item error: ' . $e->getMessage());
    $errorMessage = 'Unable to create the item report. Please try again later.';
}

?>

<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('Add item report | FindIT Admin', '../public/assets');
fiAdminNav('items');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow">

            <a href="items.php" class="fi-back-link">&larr; Back to items</a>

            <?php
            fiPageHeader(
                'Campus lost & found / Inventory',
                'Add item<br><span>report.</span>',
                'Record a found item so students can identify and claim their belongings.'
            );
            ?>

            <?php if ($errorMessage !== ''): ?>
                <?php fiAlert('danger', $errorMessage); ?>
            <?php endif; ?>

            <form method="POST" action="add-item.php" class="fi-form-card needs-validation" novalidate>
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

                        <div class="col-12">
                            <label for="id_user_pelapor" class="form-label">
                                Student reporter<span class="fi-required" aria-hidden="true">*</span>
                            </label>
                            <select
                                class="form-select"
                                id="id_user_pelapor"
                                name="id_user_pelapor"
                                required>
                                <option value="">Select a student</option>
                                <?php foreach ($students as $student): ?>
                                    <option
                                        value="<?= (int) $student['id_user'] ?>"
                                        <?= (string) $formData['id_user_pelapor'] === (string) $student['id_user']
                                            ? 'selected'
                                            : '' ?>>
                                        <?= fiE($student['name_user']) ?>
                                        (<?= fiE($student['email_kampus']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select the student who reported the item.</div>
                            <div class="form-text">
                                The report will be associated with the selected student.
                            </div>
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
                                required><?= fiE($formData['detail_lokasi']) ?></textarea>
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
                                value="<?= fiE($formData['tanggal_ditemukan']) ?>"
                                required>
                            <div class="invalid-feedback">Please choose the date found.</div>
                        </div>

                        <div class="col-12">
                            <label for="ciri_umum" class="form-label">General features</label>
                            <textarea
                                class="form-control"
                                id="ciri_umum"
                                name="ciri_umum"
                                rows="3"
                                maxlength="5000"
                                data-counter><?= fiE($formData['ciri_umum']) ?></textarea>
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
                        rows="3"
                        maxlength="5000"
                        data-counter><?= fiE($formData['ciri_khusus']) ?></textarea>
                </section>

                <div class="fi-form-actions">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        data-loading-text="Creating...">
                        Create report
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
