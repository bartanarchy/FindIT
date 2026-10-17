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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= $item ? escapeHtml($item['nama_barang']) . ' - FindIT' : 'Item Not Found - FindIT' ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <header class="border-bottom bg-white">
        <nav class="container py-3 d-flex justify-content-between align-items-center">
            <a href="index.php" class="text-decoration-none fw-bold fs-4 text-dark">
                FindIT
            </a>

            <a href="index.php" class="btn btn-outline-primary">
                Back to Home
            </a>
        </nav>
    </header>

    <main class="container py-5">

        <?php if (!$item): ?>

            <section class="text-center py-5">
                <h1 class="h3 mb-3">Item Not Found</h1>
                <p class="text-secondary">
                    The item may have been removed or the link may be invalid.
                </p>
                <a href="index.php" class="btn btn-primary">
                    Return to Homepage
                </a>
            </section>

        <?php else: ?>

            <div class="row justify-content-center">
                <div class="col-12 col-lg-8">

                    <a
                        href="index.php"
                        class="text-decoration-none d-inline-block mb-4">
                        &larr; Back to found items
                    </a>

                    <article class="card border-0 shadow-sm">
                        <div class="card-body p-4 p-md-5">

                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <span class="badge text-bg-success">
                                    <?= escapeHtml($item['status_barang']) ?>
                                </span>

                                <span class="text-secondary small">
                                    Report #<?= (int) $item['kode_laporan'] ?>
                                </span>
                            </div>

                            <h1 class="h2 fw-bold mb-3">
                                <?= escapeHtml($item['nama_barang']) ?>
                            </h1>

                            <p class="text-secondary mb-4">
                                Review the information below to check whether
                                this is the item you are looking for.
                            </p>

                            <hr>

                            <section class="mb-4">
                                <h2 class="h5 fw-semibold mb-3">
                                    Item Information
                                </h2>

                                <dl class="row mb-0">
                                    <dt class="col-sm-4 mb-2">Category</dt>
                                    <dd class="col-sm-8 mb-3">
                                        <?= displayValue($item['name_category']) ?>
                                    </dd>

                                    <dt class="col-sm-4 mb-2">Location Found</dt>
                                    <dd class="col-sm-8 mb-3">
                                        <?= displayValue($item['detail_lokasi']) ?>
                                    </dd>

                                    <dt class="col-sm-4 mb-2">Date Found</dt>
                                    <dd class="col-sm-8 mb-3">
                                        <?= displayValue($item['tanggal_ditemukan']) ?>
                                    </dd>

                                    <dt class="col-sm-4 mb-2">General Description</dt>
                                    <dd class="col-sm-8 mb-3">
                                        <?= nl2br(displayValue($item['ciri_umum'])) ?>
                                    </dd>                                    
                                </dl>
                            </section>

                            <hr>

                            <section class="mt-4">
                                <h2 class="h5 fw-semibold mb-2">
                                    Is this your item?
                                </h2>

                                <p class="text-secondary">
                                    If you believe this item belongs to you,
                                    you can proceed to the claim process.
                                </p>

                                <a
                                    href="claim.php?id=<?= (int) $item['kode_laporan'] ?>"
                                    class="btn btn-primary">
                                    Claim This Item
                                </a>
                            </section>

                        </div>
                    </article>

                </div>
            </div>

        <?php endif; ?>

    </main>

    <footer class="container py-4 text-center text-secondary small">
        <p class="mb-0">
            FindIT &mdash; Helping students reconnect with their lost belongings.
        </p>
    </footer>

</body>

</html>