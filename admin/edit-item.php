
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Item Report | FindIT</title>

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

        <h1 class="display-4 fw-bold">Edit item report.</h1>

        <p class="text-secondary">
            Update the item information and keep the report accurate.
        </p>
    </header>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= escapeEditItemHtml($errorMessage) ?>
        </div>
    <?php endif; ?>

    <section class="fi-panel p-4" aria-label="Edit item form">
        <form method="POST" action="edit-item.php?id=<?= (int) $itemId ?>">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeEditItemHtml(generateCsrfToken()) ?>"
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
                    value="<?= escapeEditItemHtml($item['nama_barang'] ?? '') ?>"
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
                            <?= (string) ($item['id_kategori'] ?? '') ===
                                (string) $category['id_category']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= escapeEditItemHtml($category['name_category']) ?>
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
                    required
                ><?= escapeEditItemHtml($item['detail_lokasi'] ?? '') ?></textarea>
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
                    value="<?= escapeEditItemHtml($item['tanggal_ditemukan'] ?? '') ?>"
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
                ><?= escapeEditItemHtml($item['ciri_umum'] ?? '') ?></textarea>
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
                ><?= escapeEditItemHtml($item['ciri_khusus'] ?? '') ?></textarea>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-dark">
                    Save changes
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
