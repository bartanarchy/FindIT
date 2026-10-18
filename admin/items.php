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
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Collect the statuses that exist so the status filter only shows useful options.
$statuses = [];

foreach ($items as $row) {
    $rowStatus = trim((string) ($row['status_barang'] ?? ''));

    if ($rowStatus !== '') {
        $statuses[$rowStatus] = $rowStatus;
    }
}

natcasesort($statuses);

fiHead('Manage items | FindIT Admin', '../public/assets');
fiAdminNav('items');
?>

<main class="fi-main" id="main">
    <div class="fi-container">

        <?php
        fiPageHeader(
            'Campus lost & found / 01',
            'Manage<br><span>items.</span>',
            'Every item has a story. Keep track of reported belongings and help them find their way back.',
            number_format($totalItems),
            'Total reports'
        );
        ?>

        <section aria-labelledby="item-reports-heading">
            <div class="fi-section-head">
                <div>
                    <p class="fi-eyebrow mb-1">The inventory</p>
                    <h2 id="item-reports-heading">Item reports</h2>
                </div>
                <a href="add-item.php" class="btn btn-primary">+ Add item</a>
            </div>

            <?php if ($errorMessage !== ''): ?>

                <?php fiAlert('danger', $errorMessage); ?>

            <?php else: ?>

                <?php if ($successMessage !== ''): ?>
                    <?php fiAlert('success', $successMessage, true); ?>
                <?php endif; ?>

                <?php if ($totalItems === 0): ?>

                    <div class="fi-empty-boxed">
                        <?php
                        fiEmptyState(
                            'No item reports yet',
                            'When an item is reported, its information will appear here.',
                            '<a href="add-item.php" class="btn btn-primary">Add the first item</a>'
                        );
                        ?>
                    </div>

                <?php else: ?>

                    <div class="fi-toolbar">
                        <div class="fi-toolbar-field">
                            <label for="item-search">Search items</label>
                            <input
                                type="search"
                                class="form-control"
                                id="item-search"
                                placeholder="Name, location, reporter or ID..."
                                autocomplete="off">
                        </div>

                        <div class="fi-toolbar-field">
                            <label for="category-filter">Category</label>
                            <select class="form-select" id="category-filter">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= fiE($category) ?>"><?= fiE($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fi-toolbar-field">
                            <label for="status-filter">Status</label>
                            <select class="form-select" id="status-filter">
                                <option value="">All statuses</option>
                                <?php foreach ($statuses as $statusOption): ?>
                                    <option value="<?= fiE($statusOption) ?>"><?= fiE($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fi-results-bar" aria-live="polite">
                        <p>
                            Showing <strong id="visible-count"><?= $totalItems ?></strong>
                            of <?= number_format($totalItems) ?> reports
                        </p>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="reset-filters">
                            Reset filters
                        </button>
                    </div>

                    <div class="fi-table-panel">
                        <div class="fi-table-wrap">
                            <table class="table fi-table">
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
                                            $dateFound,
                                        ]));
                                        ?>
                                        <tr
                                            data-search="<?= fiE($searchText) ?>"
                                            data-category="<?= fiE($categoryName) ?>"
                                            data-status="<?= fiE($status) ?>">
                                            <td data-label="Report">
                                                <span class="fi-code">#<?= (int) $item['kode_laporan'] ?></span>
                                            </td>
                                            <td data-label="Item">
                                                <span class="fi-cell-name"><?= fiE($itemName) ?></span>
                                            </td>
                                            <td data-label="Category">
                                                <span class="fi-chip">
                                                    <?= fiOr($categoryName, 'Uncategorized') ?>
                                                </span>
                                            </td>
                                            <td data-label="Location">
                                                <?= fiOr($location, 'Not specified') ?>
                                            </td>
                                            <td data-label="Reporter">
                                                <?= fiOr($reporter, 'Unknown') ?>
                                            </td>
                                            <td data-label="Date found">
                                                <?= fiDate($dateFound) ?>
                                            </td>
                                            <td data-label="Status">
                                                <?php fiBadge($status, 'Unknown'); ?>
                                            </td>
                                            <td data-label="Action">
                                                <a
                                                    href="edit-item.php?id=<?= (int) $item['kode_laporan'] ?>"
                                                    class="btn btn-sm btn-outline-secondary">
                                                    Edit
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <tr id="no-filter-results" hidden>
                                        <td colspan="8" data-label="">
                                            <?php
                                            fiEmptyState(
                                                'No matching items',
                                                'Try another search term or reset the filters.',
                                                '',
                                                '?'
                                            );
                                            ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

            <?php endif; ?>
        </section>

    </div>
</main>

<?php fiFooter('Built for the IT student community.'); ?>
<?php fiScripts('../public/assets'); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
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

        itemRows.forEach(function (row) {
            const searchableText = row.dataset.search || '';
            const category = (row.dataset.category || '').toLowerCase();
            const status = (row.dataset.status || '').toLowerCase();

            const matchesSearch = searchableText.includes(searchTerm);
            const matchesCategory = selectedCategory === '' || category === selectedCategory;
            const matchesStatus = selectedStatus === '' || status === selectedStatus;
            const shouldShow = matchesSearch && matchesCategory && matchesStatus;

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

    resetButton.addEventListener('click', function () {
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
