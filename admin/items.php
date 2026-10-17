<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';

// only admins can access this page.
requireRole('admin');

$successMessage = '';

if (($_GET['updated'] ?? '') === '1') {
    $successMessage = 'Item report updated successfully.';
} elseif (($_GET['created'] ?? '') === '1') {
    $successMessage = 'Item report created successfully.';
}

// escape database values before displaying them in HTML.
function escapeItemHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$items = [];
$categories = [];
$errorMessage = '';

try {
    // load item reports with their category and reporter names.
    $sql = '
        SELECT
            i.kode_laporan,
            i.nama_barang,
            i.status_barang,
            i.tanggal_ditemukan,
            i.detail_lokasi,
            c.name_category,
            u.name_user
        FROM public.item AS i
        LEFT JOIN public.category AS c
            ON i.id_kategori = c.id_category
        LEFT JOIN public."User" AS u
            ON i.id_user_pelapor = u.id_user
        ORDER BY i.created_at DESC
    ';

    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll();

    // build the category filter from categories actually in use.
    foreach ($items as $item) {
        $categoryName = $item['name_category'] ?? '';

        if ($categoryName !== '') {
            $categories[$categoryName] = $categoryName;
        }
    }

    natcasesort($categories);
} catch (PDOException $e) {
    // keep technical database details in the server log.
    error_log('Manage Items error: ' . $e->getMessage());
    $errorMessage = 'Unable to load item reports. Please try again later.';
}

$totalItems = count($items);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Items | FindIT</title>

    <link rel="stylesheet"
        href="../public/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet"
        href="../public/assets/css/style.css">
</head>

<body>
    <main class="fi-items-page">
        <div class="fi-container">

            <nav class="fi-items-topbar" aria-label="Admin navigation">
                <a href="index.php" class="fi-items-brand">
                    FindIT<span>.</span>
                </a>

                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="fi-items-topbar-note">Admin / Inventory</span>

                    <a href="index.php" class="btn btn-outline-dark">
                        Dashboard
                    </a>

                    <a href="../public/logout.php" class="btn btn-dark">
                        Sign out
                    </a>
                </div>
            </nav>

            <header class="fi-items-intro">
                <div>
                    <p class="fi-items-kicker">Campus lost & found / 01</p>

                    <h1 class="fi-items-title">
                        Manage<br>
                        <span>items.</span>
                    </h1>

                    <p class="fi-items-description">
                        Every item has a story. Keep track of reported belongings
                        and help them find their way back.
                    </p>
                </div>

                <div class="fi-items-total" aria-label="Total item reports">
                    <span class="fi-items-total-number">
                        <?= number_format($totalItems) ?>
                    </span>
                    <span class="fi-items-total-label">
                        Total reports
                    </span>
                </div>
            </header>

            <section aria-labelledby="item-reports-heading">
                <div class="d-flex align-items-end justify-content-between
            flex-wrap gap-2 mb-3">
                    <div>
                        <p class="fi-items-kicker mb-2">The inventory</p>
                        <h2 id="item-reports-heading" class="h4 mb-0">
                            Item reports
                        </h2>
                    </div>

                    <a href="add-item.php" class="btn btn-dark">
                        + Add item
                    </a>
                </div>


                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= escapeItemHtml($errorMessage) ?>
                    </div>

                <?php else: ?>

                    <?php if ($successMessage !== ''): ?>
                        <div class="alert alert-success" role="status">
                            <?= escapeItemHtml($successMessage) ?>
                        </div>
                    <?php endif; ?>


                    <div class="fi-items-toolbar">
                        <div class="fi-items-field">
                            <label for="item-search">Search items</label>
                            <input
                                type="search"
                                class="form-control"
                                id="item-search"
                                placeholder="Name, location, reporter or ID..."
                                autocomplete="off">
                        </div>

                        <div class="fi-items-field">
                            <label for="category-filter">Category</label>
                            <select class="form-select" id="category-filter">
                                <option value="">All categories</option>

                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= escapeItemHtml($category) ?>">
                                        <?= escapeItemHtml($category) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fi-items-field">
                            <label for="status-filter">Status</label>
                            <select class="form-select" id="status-filter">
                                <option value="">All statuses</option>

                                <?php
                                $statuses = [];

                                foreach ($items as $item) {
                                    $status = $item['status_barang'] ?? '';

                                    if ($status !== '') {
                                        $statuses[$status] = $status;
                                    }
                                }

                                natcasesort($statuses);
                                ?>

                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= escapeItemHtml($status) ?>">
                                        <?= escapeItemHtml($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fi-items-results" aria-live="polite">
                        <p>
                            Showing <strong id="visible-count"><?= $totalItems ?></strong>
                            of <?= number_format($totalItems) ?> reports
                        </p>

                        <button type="button"
                            class="btn btn-sm btn-outline-dark"
                            id="reset-filters">
                            Reset filters
                        </button>
                    </div>

                    <div class="fi-items-table-panel">
                        <?php if ($totalItems === 0): ?>
                            <div class="fi-items-empty">
                                <div class="fi-items-empty-mark" aria-hidden="true">
                                    +
                                </div>
                                <h3>No item reports yet</h3>
                                <p>
                                    When an item is reported, its information
                                    will appear here.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="fi-items-table-wrap">
                                <table class="table fi-items-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Report</th>
                                            <th scope="col">Item</th>
                                            <th scope="col">Category</th>
                                            <th scope="col">Location</th>
                                            <th scope="col">Reporter</th>
                                            <th scope="col">Date found</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Action</th>
                                        </tr>
                                    </thead>

                                    <tbody id="items-table-body">
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            $reportId = (string) $item['kode_laporan'];
                                            $itemName = (string) ($item['nama_barang'] ?? '');
                                            $categoryName = (string) ($item['name_category'] ?? '');
                                            $location = (string) ($item['detail_lokasi'] ?? '');
                                            $reporter = (string) ($item['name_user'] ?? '');
                                            $status = (string) ($item['status_barang'] ?? '');
                                            $dateFound = (string) ($item['tanggal_ditemukan'] ?? '');

                                            $searchText = strtolower(implode(' ', [
                                                $reportId,
                                                $itemName,
                                                $categoryName,
                                                $location,
                                                $reporter,
                                                $status,
                                                $dateFound
                                            ]));

                                            $statusKey = strtolower($status);
                                            ?>

                                            <tr
                                                data-search="<?= escapeItemHtml($searchText) ?>"
                                                data-category="<?= escapeItemHtml($categoryName) ?>"
                                                data-status="<?= escapeItemHtml($status) ?>">
                                                <td>
                                                    <a
                                                        href="edit-item.php?id=<?= (int) $item['kode_laporan'] ?>"
                                                        class="btn btn-sm btn-outline-dark">
                                                        Edit
                                                    </a>
                                                </td>

                                                <td>
                                                    <span class="fi-item-name">
                                                        <?= escapeItemHtml($itemName) ?>
                                                    </span>
                                                    <span class="fi-item-meta">
                                                        Campus report
                                                    </span>
                                                </td>

                                                <td>
                                                    <span class="fi-item-category">
                                                        <?= escapeItemHtml($categoryName !== '' ? $categoryName : 'Uncategorized') ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <?= escapeItemHtml($location !== '' ? $location : 'Not specified') ?>
                                                </td>

                                                <td>
                                                    <?= escapeItemHtml($reporter !== '' ? $reporter : 'Unknown') ?>
                                                </td>

                                                <td>
                                                    <?= escapeItemHtml($dateFound !== '' ? $dateFound : 'Not specified') ?>
                                                </td>

                                                <td>
                                                    <span
                                                        class="fi-item-status"
                                                        data-status="<?= escapeItemHtml($statusKey) ?>">
                                                        <?= escapeItemHtml($status !== '' ? $status : 'Unknown') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <tr id="no-filter-results" hidden>
                                            <td colspan="8">
                                                <div class="fi-items-empty">
                                                    <h3>No matching items</h3>
                                                    <p>
                                                        Try another search term or reset the filters.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="fi-items-footer">
                <span>FindIT · Campus Lost & Found</span>
                <span>Built for the IT student community.</span>
            </footer>

        </div>
    </main>

    <script src="../public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../public/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('item-search');
            const categoryFilter = document.getElementById('category-filter');
            const statusFilter = document.getElementById('status-filter');
            const resetButton = document.getElementById('reset-filters');
            const visibleCount = document.getElementById('visible-count');
            const noResultsRow = document.getElementById('no-filter-results');
            const itemRows = Array.from(
                document.querySelectorAll('#items-table-body tr[data-search]')
            );

            if (!searchInput || !categoryFilter || !statusFilter || !visibleCount) {
                return;
            }

            function filterItems() {
                const searchTerm = searchInput.value.trim().toLowerCase();
                const selectedCategory = categoryFilter.value.toLowerCase();
                const selectedStatus = statusFilter.value.toLowerCase();

                let visibleItems = 0;

                itemRows.forEach(function(row) {
                    const searchableText = row.dataset.search || '';
                    const category = (row.dataset.category || '').toLowerCase();
                    const status = (row.dataset.status || '').toLowerCase();

                    const matchesSearch = searchableText.includes(searchTerm);
                    const matchesCategory =
                        selectedCategory === '' || category === selectedCategory;
                    const matchesStatus =
                        selectedStatus === '' || status === selectedStatus;

                    const shouldShow =
                        matchesSearch && matchesCategory && matchesStatus;

                    row.hidden = !shouldShow;

                    if (shouldShow) {
                        visibleItems++;
                    }
                });

                visibleCount.textContent = visibleItems;

                if (noResultsRow) {
                    noResultsRow.hidden = visibleItems !== 0;
                }
            }

            searchInput.addEventListener('input', filterItems);
            categoryFilter.addEventListener('change', filterItems);
            statusFilter.addEventListener('change', filterItems);

            resetButton.addEventListener('click', function() {
                searchInput.value = '';
                categoryFilter.value = '';
                statusFilter.value = '';
                filterItems();
                searchInput.focus();
            });
        });
    </script>
</body>

</html>