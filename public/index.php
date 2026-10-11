
<?php

// declare(strict_types=1);

// ini buat koneksi ke database FindIT
require_once dirname(__DIR__) . '/config/config.php';

try {
    // query buat ambil semua item yang berstatus "Ditemukan" dari database.
    $sql = "
        SELECT
            i.kode_laporan,
            i.nama_barang,
            i.detail_lokasi,
            i.tanggal_ditemukan,
            c.name_category
        FROM item AS i
        INNER JOIN category AS c
            ON i.id_kategori = c.id_category
        WHERE i.status_barang = :status
        ORDER BY i.tanggal_ditemukan DESC
    ";

    // persiapkan statement PDO untuk eksekusi query.
    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'status' => 'Ditemukan',
    ]);

    // ambil semua hasil query sebagai array asosiatif.
    $items = $stmt->fetchAll();

} catch (PDOException $e) {
    // log error ke file log server untuk debugging, tapi jangan tampilkan detail error ke user.
    error_log('[FindIT] Failed to retrieve items: ' . $e->getMessage());

    // menampilkan pesan error generik ke user agar tidak mengungkapkan detail sensitif.
    http_response_code(500);
    exit('Sorry, the item catalog is currently unavailable.');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>FindIT - Lost and Found</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <main class="container py-5">
        <h1>FindIT</h1>
        <p>Find your lost belongings and help others find theirs.</p>

        <section class="row g-4">
            <?php if (empty($items)): ?>

                <div class="col-12">
                    <p>No found items are available yet.</p>
                </div>

            <?php else: ?>

                <?php foreach ($items as $item): ?>
                    <article class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100">

                            <div class="card-body">
                                <h2 class="h5">
                                    <?= htmlspecialchars(
                                        $item['nama_barang'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h2>

                                <p>
                                    Category:
                                    <?= htmlspecialchars(
                                        $item['name_category'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p>
                                    Location:
                                    <?= htmlspecialchars(
                                        $item['detail_lokasi'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p>
                                    Date Found:
                                    <?= htmlspecialchars(
                                        $item['tanggal_ditemukan'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <a
                                    class="btn btn-primary"
                                    href="item-detail.php?id=<?= (int) $item['kode_laporan'] ?>"
                                >
                                    View Details
                                </a>
                            </div>

                        </div>
                    </article>
                <?php endforeach; ?>

            <?php endif; ?>
        </section>
    </main>

</body>
</html>