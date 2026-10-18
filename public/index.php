<?php

// This file loads the database connection and login session helpers.
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';

try {
    // Get all found items so students can browse the item catalog.
    $sql = "
        SELECT
            i.kode_laporan,
            i.nama_barang,
            i.detail_lokasi,
            i.tanggal_ditemukan,
            c.name_category
        FROM public.item AS i
        INNER JOIN public.category AS c
            ON i.id_kategori = c.id_category
        WHERE i.status_barang = :status
        ORDER BY i.tanggal_ditemukan DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'status' => 'Ditemukan',
    ]);

    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    // Keep database details in the server log.
    error_log('[FindIT] Failed to retrieve items: ' . $e->getMessage());

    http_response_code(500);
    exit('Sorry, the item catalog is currently unavailable.');
}

$user = currentUser();

function escapeHome(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Build the category filter from categories that actually have items.
$categoryNames = [];

foreach ($items as $row) {
    $categoryName = trim((string) ($row['name_category'] ?? ''));

    if ($categoryName !== '') {
        $categoryNames[$categoryName] = $categoryName;
    }
}

natcasesort($categoryNames);
$totalItems = count($items);

fiHead('FindIT - Lost and Found');
fiPublicNav($user, 'home');
?>

<!-- Boostrap  -->
<main class="fi-main" id="main">
    <div class="fi-container">

        <header class="fi-hero">
            <div class="fi-hero-text">
                <p class="fi-eyebrow">Campus lost &amp; found / FindIT</p>
                <h1 class="fi-title">Lost something?<br><span>FindIT.</span></h1>
                <p class="fi-lead">
                    Find your lost belongings and help others find theirs.
                    Browse the items found around campus below.
                </p>

                <div class="fi-hero-actions">
                    <?php if ($user && $user['role'] === 'student'): ?>
                        <a href="report-item.php" class="btn btn-primary">+ Report found item</a>
                        <a href="my-claims.php" class="btn btn-outline-secondary">My claims</a>
                    <?php elseif ($user && $user['role'] === 'admin'): ?>
                        <a href="../admin/index.php" class="btn btn-primary">Open admin dashboard</a>
                    <?php else: ?>
                        <a href="register.php" class="btn btn-primary">Create an account</a>
                        <a href="login.php" class="btn btn-outline-secondary">Sign in</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="fi-count" role="group" aria-label="Items waiting for their owners">
                <span class="fi-count-number"><?= number_format($totalItems) ?></span>
                <span class="fi-count-label">Items waiting</span>
            </div>
        </header>

        <section aria-labelledby="found-items-heading">
            <div class="fi-section-head">
                <div>
                    <p class="fi-eyebrow mb-1">The inventory</p>
                    <h2 id="found-items-heading">Found items</h2>
                </div>

                <?php if ($totalItems > 0): ?>
                    <span class="small" aria-live="polite">
                        Showing <strong id="home-visible-count"><?= $totalItems ?></strong>
                        of <?= number_format($totalItems) ?> item(s)
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($totalItems === 0): ?>

                <div class="fi-empty-boxed">
                    <?php
                    fiEmptyState(
                        'No found items yet',
                        'Found item reports will appear here as soon as they are available.',
                        ($user && $user['role'] === 'student')
                            ? '<a href="report-item.php" class="btn btn-primary">Report a found item</a>'
                            : ''
                    );
                    ?>
                </div>

            <?php else: ?>

                <div class="fi-toolbar fi-toolbar--standalone">
                    <div class="fi-toolbar-field">
                        <label for="home-search">Search items</label>
                        <input
                            type="search"
                            class="form-control"
                            id="home-search"
                            placeholder="Item name, category, or location..."
                            autocomplete="off">
                    </div>

                    <div class="fi-toolbar-field">
                        <label for="home-category">Category</label>
                        <select class="form-select" id="home-category">
                            <option value="">All categories</option>
                            <?php foreach ($categoryNames as $categoryName): ?>
                                <option value="<?= fiE($categoryName) ?>">
                                    <?= fiE($categoryName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fi-toolbar-field d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary w-100" id="home-reset">
                            Reset filters
                        </button>
                    </div>
                </div>

                <div class="fi-item-grid">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $searchText = mb_strtolower(implode(' ', [
                            (string) ($item['nama_barang'] ?? ''),
                            (string) ($item['name_category'] ?? ''),
                            (string) ($item['detail_lokasi'] ?? ''),
                        ]));
                        ?>
                        <article
                            class="fi-item-card"
                            data-item-card
                            data-search="<?= fiE($searchText) ?>"
                            data-category="<?= fiE($item['name_category'] ?? '') ?>">

                            <div class="fi-item-card-top">
                                <span class="fi-chip">
                                    <?= fiOr($item['name_category'] ?? '', 'Uncategorized') ?>
                                </span>
                                <span class="fi-item-card-id">#<?= (int) $item['kode_laporan'] ?></span>
                            </div>

                            <h3 class="fi-item-card-title">
                                <?= fiE($item['nama_barang']) ?>
                            </h3>

                            <div class="fi-item-card-meta">
                                <div class="fi-field">
                                    <span class="fi-field-label">Location</span>
                                    <p class="fi-field-value">
                                        <?= fiOr($item['detail_lokasi'] ?? '', 'Not specified') ?>
                                    </p>
                                </div>

                                <div class="fi-field">
                                    <span class="fi-field-label">Date found</span>
                                    <p class="fi-field-value">
                                        <?= fiDate($item['tanggal_ditemukan'] ?? '') ?>
                                    </p>
                                </div>
                            </div>

                            <div class="fi-item-card-footer">
                                <a
                                    class="btn btn-outline-secondary w-100"
                                    href="item-detail.php?id=<?= (int) $item['kode_laporan'] ?>">
                                    View details
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div id="home-no-results" class="fi-empty-boxed mt-3" hidden>
                    <?php
                    fiEmptyState(
                        'No matching items',
                        'Try a different search term or choose another category.',
                        '',
                        '?'
                    );
                    ?>
                </div>

            <?php endif; ?>
        </section>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
