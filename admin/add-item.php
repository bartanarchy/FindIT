
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Item Report | FindIT</title>

    <link
        rel="stylesheet"
        href="../public/assets/vendor/bootstrap/css/bootstrap.min.css"
    >
    <link
        rel="stylesheet"
        href="../public/assets/css/style.css"
    >
</head>
<body>

<main class="fi-container py-5">
    <nav
        class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-5"
        aria-label="Admin navigation"
    >
        <a href="index.php" class="fi-brand text-decoration-none">
            FindIT<span>.</span>
        </a>

        <div class="d-flex gap-2 flex-wrap">
            <a href="items.php" class="btn btn-outline-dark">
                Back to items
            </a>

            <a href="index.php" class="btn btn-outline-dark">
                Dashboard
            </a>
        </div>
    </nav>

    <header class="mb-4">
        <p class="fi-eyebrow">Campus lost &amp; found / Inventory</p>

        <h1 class="display-4 fw-bold">Add item report.</h1>

        <p class="text-secondary">
            Record a found item so students can identify and claim their belongings.
        </p>
    </header>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= escapeAddItemHtml($errorMessage) ?>
        </div>
    <?php endif; ?>

    <section class="fi-panel p-4" aria-label="Add item form">
        <form method="POST" action="add-item.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeAddItemHtml(generateCsrfToken()) ?>"
            >

            <div class="mb-3">
                <label for="nama_barang" class="form-label">
                    Item name
                </label>

                <input
                    type="text"
                    class="form-control"
                    id="nama_barang"
                    name="nama_barang"
                    maxlength="100"
                    value="<?= escapeAddItemHtml($formData['nama_barang']) ?>"
                    required
                >
            </div>

            <div class="mb-3">
                <label for="id_kategori" class="form-label">
                    Category
                </label>

                <select
                    class="form-select"
                    id="id_kategori"
                    name="id_kategori"
                    required
                >
                    <option value="">Select a category</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= (int) $category['id_category'] ?>"
                            <?= (string) $formData['id_kategori'] ===
                                (string) $category['id_category']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= escapeAddItemHtml($category['name_category']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="id_user_pelapor" class="form-label">
                    Student reporter
                </label>

                <select
                    class="form-select"
                    id="id_user_pelapor"
                    name="id_user_pelapor"
                    required
                >
                    <option value="">Select a student</option>

                    <?php foreach ($students as $student): ?>
                        <option
                            value="<?= (int) $student['id_user'] ?>"
                            <?= (string) $formData['id_user_pelapor'] ===
                                (string) $student['id_user']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= escapeAddItemHtml($student['name_user']) ?>
                            (<?= escapeAddItemHtml($student['email_kampus']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="form-text">
                    The report will be associated with the selected student.
                </div>
            </div>

            <div class="mb-3">
                <label for="detail_lokasi" class="form-label">
                    Location found
                </label>

                <textarea
                    class="form-control"
                    id="detail_lokasi"
                    name="detail_lokasi"
                    rows="2"
                    maxlength="5000"
                    required
                ><?= escapeAddItemHtml($formData['detail_lokasi']) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="tanggal_ditemukan" class="form-label">
                    Date found
                </label>

                <input
                    type="date"
                    class="form-control"
                    id="tanggal_ditemukan"
                    name="tanggal_ditemukan"
                    value="<?= escapeAddItemHtml($formData['tanggal_ditemukan']) ?>"
                    required
                >
            </div>

            <div class="mb-3">
                <label for="ciri_umum" class="form-label">
                    General features
                </label>

                <textarea
                    class="form-control"
                    id="ciri_umum"
                    name="ciri_umum"
                    rows="3"
                    maxlength="5000"
                ><?= escapeAddItemHtml($formData['ciri_umum']) ?></textarea>
            </div>

            <div class="mb-4">
                <label for="ciri_khusus" class="form-label">
                    Distinctive features
                </label>

                <textarea
                    class="form-control"
                    id="ciri_khusus"
                    name="ciri_khusus"
                    rows="3"
                    maxlength="5000"
                ><?= escapeAddItemHtml($formData['ciri_khusus']) ?></textarea>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-dark">
                    Create report
                </button>

                <a href="items.php" class="btn btn-outline-dark">
                    Cancel
                </a>
            </div>
        </form>
    </section>

    <footer class="mt-4 text-secondary small">
        FindIT · Campus Lost &amp; Found
    </footer>
</main>

<script src="../public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../public/assets/js/app.js"></script>

</body>
</html>
