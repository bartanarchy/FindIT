
<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';

// Only logged-in students can view their own claims.
requireRole('student');

function escapeMyClaim(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);

$claims = [];
$errorMessage = '';

try {
    // Only retrieve claims submitted by the currently logged-in student.
    $sql = '
        SELECT
            c.id_claim,
            c.kode_klaim,
            c.status_klaim,
            c.created_at,
            c.updated_at,
            c.catatan_admin,
            c.kode_pengambilan,
            c.lokasi_pengambilan,
            i.kode_laporan,
            i.nama_barang,
            i.detail_lokasi,
            i.tanggal_ditemukan,
            cat.name_category
        FROM public.claim AS c
        LEFT JOIN public.item AS i
            ON c.id_item = i.kode_laporan
        LEFT JOIN public.category AS cat
            ON i.id_kategori = cat.id_category
        WHERE c.id_user_pengaju = :user_id
        ORDER BY c.created_at DESC NULLS LAST, c.id_claim DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $claims = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[FindIT] Failed to load student claims: ' . $e->getMessage());
    $errorMessage = 'Unable to load your claim history. Please try again later.';
}

$totalClaims = count($claims);

function getClaimStatusClass(?string $status): string
{
    switch ($status) {
        case 'Disetujui':
            return 'my-claim-status--approved';

        case 'Ditolak':
            return 'my-claim-status--rejected';

        default:
            return 'my-claim-status--pending';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Claims | FindIT</title>

    <link rel="stylesheet"
          href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet"
          href="assets/css/style.css">

    <style>
        /* Temporary styles for the student claim history page. */
        .my-claims-page {
            min-height: 100vh;
            padding: 36px 0 60px;
            background: #F4F0E8;
            color: #20251C;
        }

        .my-claims-container {
            width: min(100% - 32px, 1000px);
            margin: 0 auto;
        }

        .my-claims-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            margin: 42px 0 32px;
        }

        .my-claims-eyebrow {
            margin-bottom: 12px;
            color: #69705F;
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .my-claims-title {
            margin: 0 0 12px;
            font-size: clamp(2.5rem, 7vw, 4.5rem);
            font-weight: 800;
            letter-spacing: -0.065em;
            line-height: 0.98;
        }

        .my-claims-description {
            max-width: 540px;
            color: #65695F;
            line-height: 1.7;
        }

        .my-claims-count {
            min-width: 145px;
            padding: 20px;
            background: #D9F36A;
            border-radius: 14px;
        }

        .my-claims-count-number {
            display: block;
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.05em;
        }

        .my-claims-count-label {
            font-size: 0.85rem;
        }

        .my-claim-card {
            margin-bottom: 18px;
            padding: 24px;
            background: #FFFFFF;
            border: 1px solid #E5E1D8;
            border-radius: 16px;
        }

        .my-claim-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
        }

        .my-claim-item-name {
            margin-bottom: 7px;
            font-size: 1.3rem;
            font-weight: 750;
            letter-spacing: -0.03em;
        }

        .my-claim-reference {
            color: #74786D;
            font-size: 0.8rem;
            overflow-wrap: anywhere;
        }

        .my-claim-status {
            display: inline-block;
            flex-shrink: 0;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .my-claim-status--pending {
            background: #FFF0C2;
            color: #725400;
        }

        .my-claim-status--approved {
            background: #E5F2D5;
            color: #35551D;
        }

        .my-claim-status--rejected {
            background: #F9DFDC;
            color: #8B3027;
        }

        .my-claim-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .my-claim-detail-label {
            display: block;
            margin-bottom: 5px;
            color: #777B71;
            font-size: 0.75rem;
        }

        .my-claim-detail-value {
            overflow-wrap: anywhere;
            font-size: 0.92rem;
        }

        .my-claim-pickup {
            margin-top: 22px;
            padding: 18px;
            background: #F1F4E9;
            border: 1px solid #DCE5C9;
            border-radius: 12px;
        }

        .my-claim-pickup-code {
            margin: 6px 0;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            overflow-wrap: anywhere;
        }

        .my-claims-empty {
            padding: 44px 24px;
            background: #FFFFFF;
            border: 1px solid #E5E1D8;
            border-radius: 16px;
            text-align: center;
        }

        .my-claims-footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 32px;
            color: #777B71;
            font-size: 0.8rem;
        }

        @media (max-width: 600px) {
            .my-claims-page {
                padding-top: 24px;
            }

            .my-claims-header {
                flex-direction: column;
                margin-top: 30px;
            }

            .my-claims-count {
                width: 100%;
            }

            .my-claim-card {
                padding: 18px;
            }

            .my-claim-card-header {
                flex-direction: column;
            }

            .my-claim-details {
                grid-template-columns: 1fr;
            }

            .my-claims-footer {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<header class="border-bottom bg-white">
    <nav class="container py-3 d-flex justify-content-between align-items-center gap-3">
        <a href="index.php"
           class="text-decoration-none fw-bold fs-4 text-dark">
            FindIT<span style="color: #7B913E;">.</span>
        </a>

        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-outline-dark">
                Home
            </a>

            <a href="logout.php" class="btn btn-dark">
                Sign out
            </a>
        </div>
    </nav>
</header>

<main class="my-claims-page">
    <div class="my-claims-container">

        <header class="my-claims-header">
            <div>
                <p class="my-claims-eyebrow">Campus lost & found / Student</p>

                <h1 class="my-claims-title">
                    My<br>claims.
                </h1>

                <p class="my-claims-description">
                    Track your claim requests and check whether your item
                    is ready for pickup.
                </p>
            </div>

            <div class="my-claims-count">
                <span class="my-claims-count-number">
                    <?= number_format($totalClaims) ?>
                </span>
                <span class="my-claims-count-label">Your claim requests</span>
            </div>
        </header>

        <?php if ($errorMessage !== ''): ?>

            <div class="alert alert-danger" role="alert">
                <?= escapeMyClaim($errorMessage) ?>
            </div>

        <?php elseif ($totalClaims === 0): ?>

            <section class="my-claims-empty">
                <h2 class="h4 mb-3">No claims yet</h2>

                <p class="text-secondary mb-4">
                    When you submit a claim for a found item, it will appear here.
                </p>

                <a href="index.php" class="btn btn-dark">
                    Browse found items
                </a>
            </section>

        <?php else: ?>

            <?php foreach ($claims as $claim): ?>
                <?php
                $status = (string) ($claim['status_klaim'] ?? '');
                $isApproved = $status === 'Disetujui';
                ?>

                <article class="my-claim-card">
                    <div class="my-claim-card-header">
                        <div>
                            <h2 class="my-claim-item-name">
                                <?= escapeMyClaim($claim['nama_barang'] ?? 'Item unavailable') ?>
                            </h2>

                            <div class="my-claim-reference">
                                Reference:
                                <?= escapeMyClaim($claim['kode_klaim'] ?? 'Not available') ?>
                            </div>
                        </div>

                        <span class="my-claim-status <?= getClaimStatusClass($status) ?>">
                            <?= escapeMyClaim($status !== '' ? $status : 'Not set') ?>
                        </span>
                    </div>

                    <div class="my-claim-details">
                        <div>
                            <span class="my-claim-detail-label">Category</span>
                            <div class="my-claim-detail-value">
                                <?= escapeMyClaim($claim['name_category'] ?? 'Uncategorized') ?>
                            </div>
                        </div>

                        <div>
                            <span class="my-claim-detail-label">Report ID</span>
                            <div class="my-claim-detail-value">
                                <?= $claim['kode_laporan'] !== null
                                    ? '#' . (int) $claim['kode_laporan']
                                    : 'Item unavailable' ?>
                            </div>
                        </div>

                        <div>
                            <span class="my-claim-detail-label">Claim submitted</span>
                            <div class="my-claim-detail-value">
                                <?= escapeMyClaim($claim['created_at'] ?? 'Not available') ?>
                            </div>
                        </div>

                        <div>
                            <span class="my-claim-detail-label">Last updated</span>
                            <div class="my-claim-detail-value">
                                <?= escapeMyClaim($claim['updated_at'] ?? 'Not available') ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($isApproved): ?>
                        <section class="my-claim-pickup"
                                 aria-label="Pickup information">
                            <p class="my-claims-eyebrow mb-2">
                                Claim approved / Pickup information
                            </p>

                            <span class="my-claim-detail-label">Pickup code</span>

                            <div class="my-claim-pickup-code">
                                <?= escapeMyClaim(
                                    $claim['kode_pengambilan'] ?? 'Not available'
                                ) ?>
                            </div>

                            <span class="my-claim-detail-label mt-3">
                                Pickup location
                            </span>

                            <div class="my-claim-detail-value">
                                <?= escapeMyClaim(
                                    $claim['lokasi_pengambilan'] ?? 'Not assigned'
                                ) ?>
                            </div>
                        </section>

                    <?php elseif ($status === 'Ditolak'): ?>

                        <div class="alert alert-danger mt-4 mb-0">
                            <strong>Claim not approved.</strong>

                            <?php if (!empty($claim['catatan_admin'])): ?>
                                <p class="mb-0 mt-2">
                                    <?= nl2br(escapeMyClaim($claim['catatan_admin'])) ?>
                                </p>
                            <?php else: ?>
                                <p class="mb-0 mt-2">
                                    Please contact the FindIT administrator if you need more information.
                                </p>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <div class="alert alert-warning mt-4 mb-0">
                            Your claim is waiting for admin verification.
                        </div>

                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

        <?php endif; ?>

        <footer class="my-claims-footer">
            <span>FindIT · Campus Lost & Found</span>
            <span>Student claim history</span>
        </footer>

    </div>
</main>

</body>
</html>
