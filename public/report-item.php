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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Report Found Item | FindIT</title>

    <link
        rel="stylesheet"
        href="assets/vendor/bootstrap/css/bootstrap.min.css"
    >
    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >
</head>
<body>

<header class="border-bottom bg-white">
    <nav class="container py-3 d-flex justify-content-between align-items-center gap-3">
        <a
            href="index.php"
            class="text-decoration-none fw-bold fs-4 text-dark"
        >
            FindIT<span style="color: #7B913E;">.</span>
        </a>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="index.php" class="btn btn-outline-dark">
                Home
            </a>

            <a href="my-claims.php" class="btn btn-outline-dark">
                My Claims
            </a>

            <a href="logout.php" class="btn btn-dark">
                Sign out
            </a>
        </div>
    </nav>
</header>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">

            <p class="text-secondary small mb-2">
                CAMPUS LOST &amp; FOUND / REPORT ITEM
            </p>

            <h1 class="display-5 fw-bold mb-3">
                Report a found item.
            </h1>

            <p class="text-secondary mb-4">
                Share the general details so the owner can recognize the item.
                Keep distinctive features private for verification.
            </p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= escapeReportItemHtml($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="status">
                    <?= escapeReportItemHtml($successMessage) ?>
                </div>
            <?php endif; ?>

            <section class="fi-panel p-4">
                <form method="POST" action="report-item.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escapeReportItemHtml(generateCsrfToken()) ?>"
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
                            value="<?= escapeReportItemHtml($formData['nama_barang']) ?>"
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
                                    <?= escapeReportItemHtml($category['name_category']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                        ><?= escapeReportItemHtml($formData['detail_lokasi']) ?></textarea>
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
                            max="<?= date('Y-m-d') ?>"
                            value="<?= escapeReportItemHtml($formData['tanggal_ditemukan']) ?>"
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
                            placeholder="Describe the item's visible appearance."
                        ><?= escapeReportItemHtml($formData['ciri_umum']) ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label for="ciri_khusus" class="form-label">
                            Distinctive features (private)
                        </label>

                        <textarea
                            class="form-control"
                            id="ciri_khusus"
                            name="ciri_khusus"
                            rows="3"
                            maxlength="5000"
                            placeholder="Enter details only the finder and admin should know."
                            required
                        ><?= escapeReportItemHtml($formData['ciri_khusus']) ?></textarea>

                        <div class="form-text">
                            This information will not be shown on the public item listing.
                            The admin will use it to help verify ownership claims.
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-dark">
                            Submit report
                        </button>

                        <a href="index.php" class="btn btn-outline-dark">
                            Cancel
                        </a>
                    </div>
                </form>
            </section>

        </div>
    </div>
</main>

<footer class="container py-4 text-center text-secondary small">
    FindIT — Helping students reconnect with their lost belongings.
</footer>

<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>

</body>
</html>
