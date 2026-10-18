<?php

require_once dirname(__DIR__) . '/config/config.php';

// get the item ID from the URL because each item has its own detail page.
$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

try {
    $sql = "
        SELECT
            i.kode_laporan,
            i.nama_barang,
            i.detail_lokasi,
            i.tanggal_ditemukan,
            i.ciri_umum,
            i.status_barang,
            c.name_category
        FROM public.item AS i
        INNER JOIN public.category AS c
            ON i.id_kategori = c.id_category
        WHERE i.kode_laporan = :item_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'item_id' => $itemId,
    ]);

    $item = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[FindIT] Failed to retrieve item details: ' . $e->getMessage());
    http_response_code(500);
    exit('Sorry, the item details are currently unavailable.');
}

// we dont want to run the query if the ID is missing or invalid.
if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}
function escapeHtml($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function displayValue($value): string
{
    return $value !== null && trim((string) $value) !== ''
        ? escapeHtml($value)
        : 'Not provided';
}

?>
<?php

require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';

$viewer = currentUser();
$pageTitle = $item
    ? $item['nama_barang'] . ' - FindIT'
    : 'Item Not Found - FindIT';
$isAvailable = $item && $item['status_barang'] === 'Ditemukan';

fiHead($pageTitle);
fiPublicNav($viewer, 'home');
?>

<main class="fi-main" id="main">
    <div class="fi-container">

        <?php if (!$item): ?>

            <div class="fi-narrow fi-empty-boxed">
                <?php
                fiEmptyState(
                    'Item not found',
                    'The item may have been removed or the link may be invalid.',
                    '<a href="index.php" class="btn btn-primary">Return to homepage</a>',
                    '?'
                );
                ?>
            </div>

        <?php else: ?>

            <a href="index.php" class="fi-back-link">&larr; Back to found items</a>

            <div class="row g-4">
                <div class="col-12 col-lg-8">
                    <article class="fi-panel">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <?php fiBadge($item['status_barang']); ?>
                            <span class="small">Report #<?= (int) $item['kode_laporan'] ?></span>
                        </div>

                        <h1 class="fi-title mb-3" style="font-size: clamp(1.9rem, 4.5vw, 2.8rem);">
                            <?= fiE($item['nama_barang']) ?>
                        </h1>

                        <p class="text-secondary mb-4">
                            Review the information below to check whether
                            this is the item you are looking for.
                        </p>

                        <h2 class="fi-form-section-title mb-3">Item information</h2>

                        <dl class="fi-fields mb-0">
                            <div class="fi-field">
                                <dt class="fi-field-label">Category</dt>
                                <dd class="fi-field-value">
                                    <span class="fi-chip"><?= fiOr($item['name_category']) ?></span>
                                </dd>
                            </div>

                            <div class="fi-field">
                                <dt class="fi-field-label">Date found</dt>
                                <dd class="fi-field-value"><?= fiDate($item['tanggal_ditemukan'], 'Not provided') ?></dd>
                            </div>

                            <div class="fi-field fi-field--wide">
                                <dt class="fi-field-label">Location found</dt>
                                <dd class="fi-field-value"><?= nl2br(fiOr($item['detail_lokasi'])) ?></dd>
                            </div>

                            <div class="fi-field fi-field--wide">
                                <dt class="fi-field-label">General description</dt>
                                <dd class="fi-field-value"><?= nl2br(fiOr($item['ciri_umum'])) ?></dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <aside class="col-12 col-lg-4">
                    <div class="fi-panel fi-sticky">
                        <p class="fi-panel-kicker">Claim process</p>
                        <h2 class="h5 mb-2">Is this your item?</h2>

                        <?php if ($isAvailable): ?>
                            <p class="text-secondary">
                                If you believe this item belongs to you, continue to the claim
                                form. An admin will verify your answers before pickup.
                            </p>

                            <a
                                href="claim.php?id=<?= (int) $item['kode_laporan'] ?>"
                                class="btn btn-primary w-100">
                                Claim this item
                            </a>

                            <?php if (!$viewer): ?>
                                <p class="small mt-3 mb-0">
                                    You need to <a href="login.php">sign in</a> as a student
                                    before you can submit a claim.
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-secondary mb-3">
                                This item is no longer available for new claims.
                            </p>
                            <a href="index.php" class="btn btn-outline-secondary w-100">
                                Browse other items
                            </a>
                        <?php endif; ?>

                        <div class="fi-callout mt-4">
                            Distinctive features are kept private. You will be asked to
                            describe them to prove ownership.
                        </div>
                    </div>
                </aside>
            </div>

        <?php endif; ?>

    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
