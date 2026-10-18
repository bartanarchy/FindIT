
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
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

fiHead('My claims | FindIT');
fiPublicNav(currentUser(), 'claims');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow" style="width: min(900px, 100%);">

            <?php
            fiPageHeader(
                'Campus lost & found / Student',
                'My<br><span>claims.</span>',
                'Track your claim requests and check whether your item is ready for pickup.',
                number_format($totalClaims),
                'Your claim requests'
            );
            ?>

            <?php if ($errorMessage !== ''): ?>

                <?php fiAlert('danger', $errorMessage); ?>

            <?php elseif ($totalClaims === 0): ?>

                <div class="fi-empty-boxed">
                    <?php
                    fiEmptyState(
                        'No claims yet',
                        'When you submit a claim for a found item, it will appear here.',
                        '<a href="index.php" class="btn btn-primary">Browse found items</a>'
                    );
                    ?>
                </div>

            <?php else: ?>

                <div class="fi-claim-list">
                    <?php foreach ($claims as $claim): ?>
                        <?php
                        $status = (string) ($claim['status_klaim'] ?? '');
                        $isApproved = $status === 'Disetujui';
                        $pickupCode = (string) ($claim['kode_pengambilan'] ?? '');
                        ?>
                        <article class="fi-claim-card">
                            <div class="fi-claim-head">
                                <div>
                                    <h2 class="fi-claim-title">
                                        <?= fiOr($claim['nama_barang'] ?? '', 'Item unavailable') ?>
                                    </h2>
                                    <p class="fi-claim-ref">
                                        Reference:
                                        <span class="fi-code"><?= fiOr($claim['kode_klaim'] ?? '', 'Not available') ?></span>
                                    </p>
                                </div>
                                <?php fiBadge($status); ?>
                            </div>

                            <dl class="fi-fields mb-0">
                                <div class="fi-field">
                                    <dt class="fi-field-label">Category</dt>
                                    <dd class="fi-field-value"><?= fiOr($claim['name_category'] ?? '', 'Uncategorized') ?></dd>
                                </div>

                                <div class="fi-field">
                                    <dt class="fi-field-label">Report ID</dt>
                                    <dd class="fi-field-value">
                                        <?= $claim['kode_laporan'] !== null
                                            ? '#' . (int) $claim['kode_laporan']
                                            : 'Item unavailable' ?>
                                    </dd>
                                </div>

                                <div class="fi-field">
                                    <dt class="fi-field-label">Claim submitted</dt>
                                    <dd class="fi-field-value"><?= fiDateTime($claim['created_at'] ?? '') ?></dd>
                                </div>

                                <div class="fi-field">
                                    <dt class="fi-field-label">Last updated</dt>
                                    <dd class="fi-field-value"><?= fiDateTime($claim['updated_at'] ?? '') ?></dd>
                                </div>
                            </dl>

                            <?php if ($isApproved): ?>

                                <section class="fi-pickup" aria-label="Pickup information">
                                    <p class="fi-eyebrow mb-2">Claim approved / Pickup information</p>

                                    <span class="fi-field-label">Pickup code</span>
                                    <div class="fi-pickup-code-row">
                                        <span class="fi-pickup-code">
                                            <?= fiOr($pickupCode, 'Not available') ?>
                                        </span>
                                        <?php if ($pickupCode !== ''): ?>
                                            <button
                                                type="button"
                                                class="btn btn-soft btn-sm"
                                                data-copy="<?= fiE($pickupCode) ?>">
                                                Copy code
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <span class="fi-field-label">Pickup location</span>
                                    <p class="fi-field-value mb-0">
                                        <?= fiOr($claim['lokasi_pengambilan'] ?? '', 'Not assigned') ?>
                                    </p>
                                </section>

                            <?php elseif ($status === 'Ditolak'): ?>

                                <div class="alert alert-danger fi-alert fi-note" role="status">
                                    <span class="fi-alert-dot" aria-hidden="true"></span>
                                    <div class="fi-alert-text">
                                        <strong>Claim not approved.</strong>
                                        <?php if (!empty($claim['catatan_admin'])): ?>
                                            <div class="mt-1"><?= nl2br(fiE($claim['catatan_admin'])) ?></div>
                                        <?php else: ?>
                                            <div class="mt-1">
                                                Please contact the FindIT administrator if you need more information.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            <?php else: ?>

                                <div class="alert alert-warning fi-alert fi-note" role="status">
                                    <span class="fi-alert-dot" aria-hidden="true"></span>
                                    <div class="fi-alert-text">
                                        Your claim is waiting for admin verification.
                                    </div>
                                </div>

                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </div>
    </div>
</main>

<?php fiFooter('Student claim history'); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
