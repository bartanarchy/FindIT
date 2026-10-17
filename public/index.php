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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>FindIT - Lost and Found</title>

    <link rel="stylesheet"
        href="assets/vendor/bootstrap/css/bootstrap.min.css">

    <link rel="stylesheet"
        href="assets/css/style.css">
</head>

<body>

    <header class="border-bottom bg-white">
        <nav class="container py-3 d-flex justify-content-between align-items-center gap-3">
            <a href="index.php"
                class="text-decoration-none fw-bold fs-4 text-dark">
                FindIT<span style="color: #7B913E;">.</span>
            </a>

            <div class="d-flex align-items-center gap-2 flex-wrap">

                <?php if ($user && $user['role'] === 'student'): ?>

                    <a href="report-item.php" class="btn btn-dark">
                        + Report Found Item
                    </a>

                    <a href="my-claims.php" class="btn btn-outline-dark">
                        My Claims
                    </a>

                    <span class="text-secondary small d-none d-md-inline">
                        <?= escapeHome($user['name'] ?? 'Student') ?>
                    </span>

                    <a href="logout.php" class="btn btn-dark">
                        Sign out
                    </a>

                <?php elseif ($user && $user['role'] === 'admin'): ?>

                    <a href="../admin/index.php" class="btn btn-outline-dark">
                        Admin Dashboard
                    </a>

                    <a href="logout.php" class="btn btn-dark">
                        Sign out
                    </a>

                <?php else: ?>

                    <a href="login.php" class="btn btn-outline-dark">
                        Sign in
                    </a>

                    <a href="register.php" class="btn btn-dark">
                        Register
                    </a>

                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main class="container py-5">
        <section class="mb-5">
            <p class="text-secondary small mb-2">
                CAMPUS LOST & FOUND / FINDIT
            </p>

            <h1 class="display-4 fw-bold">FindIT</h1>

            <p class="text-secondary">
                Find your lost belongings and help others find theirs.
            </p>
        </section>

        <section aria-labelledby="found-items-heading">
            <div class="d-flex justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <p class="text-secondary small mb-1">THE INVENTORY</p>
                    <h2 id="found-items-heading" class="h3 mb-0">
                        Found items
                    </h2>
                </div>

                <span class="text-secondary small">
                    <?= count($items) ?> item(s)
                </span>
            </div>

            <div class="row g-4">
                <?php if (empty($items)): ?>

                    <div class="col-12">
                        <div class="p-4 border rounded-3 bg-white">
                            <h3 class="h5">No found items yet</h3>
                            <p class="text-secondary mb-0">
                                Found item reports will appear here when available.
                            </p>
                        </div>
                    </div>

                <?php else: ?>

                    <?php foreach ($items as $item): ?>
                        <article class="col-12 col-md-6 col-lg-4">
                            <div class="card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <p class="text-secondary small mb-2">
                                        <?= escapeHome($item['name_category'] ?? 'Uncategorized') ?>
                                    </p>

                                    <h3 class="h5 fw-bold">
                                        <?= escapeHome($item['nama_barang']) ?>
                                    </h3>

                                    <p class="mb-2">
                                        <strong>Location:</strong>
                                        <?= escapeHome($item['detail_lokasi'] ?? 'Not specified') ?>
                                    </p>

                                    <p class="text-secondary small mb-4">
                                        <strong>Date found:</strong>
                                        <?= escapeHome($item['tanggal_ditemukan'] ?? 'Not specified') ?>
                                    </p>

                                    <a
                                        class="btn btn-dark"
                                        href="item-detail.php?id=<?= (int) $item['kode_laporan'] ?>">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="container py-4 text-center text-secondary small">
        FindIT — Helping students reconnect with their lost belongings.
    </footer>

    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>

</html>