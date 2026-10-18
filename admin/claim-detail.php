<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// Only admins can access claim details.
requireRole('admin');

function escapeClaimDetail(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$claimId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$claim = null;
$errorMessage = '';
$actionMessage = '';
$actionError = '';

if (!$claimId || $claimId < 1) {
    http_response_code(400);
    $errorMessage = 'Invalid claim ID.';
} else {
    // Load the claim before processing a decision, so we can verify it exists.
    try {

        $sql = '
    SELECT
        c.id_claim,
        c.kode_klaim,
        c.status_klaim,
        c.created_at,
        c.updated_at,
        c.lokasi_kehilangan,
        c.terakhir_di_lihat,
        c.ciri_khusus_claim,
        c.catatan_admin,
        c.kode_pengambilan,
        c.lokasi_pengambilan,
        i.kode_laporan,
        i.nama_barang,
        i.status_barang,
        i.detail_lokasi,
        i.tanggal_ditemukan,
        i.ciri_umum,
        i.ciri_khusus,
        u.name_user,
        u.email_kampus,
        u.no_telp,
        a.nama_admin
    FROM public.claim AS c
    LEFT JOIN public.item AS i ON c.id_item = i.kode_laporan
    LEFT JOIN public."User" AS u ON c.id_user_pengaju = u.id_user
    LEFT JOIN public.admin AS a ON c.id_admin_verifikator = a.id_admin
    WHERE c.id_claim = :claim_id
    LIMIT 1
';

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['claim_id' => $claimId]);
        $claim = $stmt->fetch() ?: null;

        if (!$claim) {
            http_response_code(404);
            $errorMessage = 'Claim request not found.';
        }
    } catch (PDOException $e) {
        error_log('[FindIT] Claim detail error: ' . $e->getMessage());
        http_response_code(500);
        $errorMessage = 'Unable to load this claim request. Please try again later.';
    }
}

// Process an admin decision only for an existing claim.
if ($claim && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $decision = $_POST['decision'] ?? '';
    $adminNote = $_POST['catatan_admin'] ?? '';
    $pickupLocation = $_POST['lokasi_pengambilan'] ?? '';

    $csrfToken = is_string($csrfToken) ? $csrfToken : '';
    $decision = is_string($decision) ? $decision : '';
    $adminNote = is_string($adminNote) ? trim($adminNote) : '';
    $pickupLocation = is_string($pickupLocation) ? trim($pickupLocation) : '';

    if (!verifyCsrfToken($csrfToken)) {
        $actionError = 'Your form session has expired. Refresh the page and try again.';
    } elseif (!in_array($decision, ['approve', 'reject'], true)) {
        $actionError = 'Invalid decision. Please choose approve or reject.';
    } elseif ($adminNote === '') {
        $actionError = 'Please enter an admin note explaining your decision.';
    } elseif (mb_strlen($adminNote) > 5000) {
        $actionError = 'The admin note must not exceed 5,000 characters.';
    } elseif ($decision === 'approve' && ($pickupLocation === '' || mb_strlen($pickupLocation) > 100)) {
        $actionError = 'Enter a pickup location of no more than 100 characters.';
    } else {
        $admin = currentUser();
        $adminId = (int) ($admin['id'] ?? 0);

        if ($adminId < 1) {
            $actionError = 'Your admin session is invalid. Please sign in again.';
        } else {
            try {
                $pdo->beginTransaction();
                $lockStmt = $pdo->prepare(
                    'SELECT id_claim, status_klaim FROM public.claim WHERE id_claim = :claim_id FOR UPDATE'
                );
                $lockStmt->execute(['claim_id' => $claimId]);
                $lockedClaim = $lockStmt->fetch();

                if (!$lockedClaim) {
                    throw new RuntimeException('Claim request not found.');
                }
                if ($lockedClaim['status_klaim'] !== 'Menunggu Verifikasi') {
                    throw new RuntimeException('This claim has already been reviewed and cannot be changed again.');
                }

                if ($decision === 'approve') {
                    $newStatus = 'Disetujui';
                    $pickupCode = 'PICK-' . strtoupper(bin2hex(random_bytes(4)));
                    $savedPickupLocation = $pickupLocation;
                } else {
                    $newStatus = 'Ditolak';
                    $pickupCode = null;
                    $savedPickupLocation = null;
                }

                $updateStmt = $pdo->prepare('
                    UPDATE public.claim
                    SET status_klaim = :status,
                        catatan_admin = :admin_note,
                        id_admin_verifikator = :admin_id,
                        kode_pengambilan = :pickup_code,
                        lokasi_pengambilan = :pickup_location,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id_claim = :claim_id
                      AND status_klaim = :pending_status
                ');
                $updateStmt->execute([
                    'status' => $newStatus,
                    'admin_note' => $adminNote,
                    'admin_id' => $adminId,
                    'pickup_code' => $pickupCode,
                    'pickup_location' => $savedPickupLocation,
                    'claim_id' => $claimId,
                    'pending_status' => 'Menunggu Verifikasi',
                ]);

                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('The claim was not updated. Reload the page and try again.');
                }

                $pdo->commit();
                header('Location: claim-detail.php?id=' . $claimId . '&updated=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[FindIT] Claim decision failed: ' . $e->getMessage());
                $actionError = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to save the decision. Please try again.';
            }
        }
    }
}

// Reload the claim after an update or page refresh.
if ($claimId && $errorMessage === '') {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['claim_id' => $claimId]);
        $claim = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('[FindIT] Failed to refresh claim details: ' . $e->getMessage());
        if ($errorMessage === '') {
            $errorMessage = 'Unable to refresh this claim request. Please try again later.';
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $actionMessage = 'The claim decision has been saved successfully.';
}
?>
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

$isPending = $claim && ($claim['status_klaim'] ?? '') === 'Menunggu Verifikasi';

fiHead('Claim details | FindIT Admin', '../public/assets');
fiAdminNav('claims');
?>

<main class="fi-main" id="main">
    <div class="fi-container">

        <a href="claims.php" class="fi-back-link">&larr; Back to claims</a>

        <?php
        fiPageHeader(
            'Campus lost & found / Claim review',
            'Claim<br><span>details.</span>',
            'Review the submitted information before making a decision.',
            $claim ? '#' . (int) $claim['id_claim'] : null,
            'Internal claim ID'
        );
        ?>

        <?php if ($errorMessage !== ''): ?>

            <?php fiAlert('danger', $errorMessage); ?>
            <a href="claims.php" class="btn btn-outline-secondary">Return to claims</a>

        <?php elseif ($claim): ?>

            <?php if ($actionMessage !== ''): ?>
                <?php fiAlert('success', $actionMessage, true); ?>
            <?php endif; ?>

            <?php if ($actionError !== ''): ?>
                <?php fiAlert('danger', $actionError); ?>
            <?php endif; ?>

            <div class="fi-review">

                <div>
                    <!-- Request overview -->
                    <section class="fi-panel" aria-labelledby="overview-heading">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                            <div>
                                <p class="fi-panel-kicker">Request overview</p>
                                <h2 id="overview-heading" class="h4 mb-0">
                                    <?= fiE($claim['kode_klaim'] ?? 'Claim #' . $claim['id_claim']) ?>
                                </h2>
                            </div>
                            <?php fiBadge($claim['status_klaim'] ?? ''); ?>
                        </div>

                        <dl class="fi-fields mb-0">
                            <div class="fi-field">
                                <dt class="fi-field-label">Submitted</dt>
                                <dd class="fi-field-value"><?= fiDateTime($claim['created_at'] ?? '') ?></dd>
                            </div>
                            <div class="fi-field">
                                <dt class="fi-field-label">Last updated</dt>
                                <dd class="fi-field-value"><?= fiDateTime($claim['updated_at'] ?? '') ?></dd>
                            </div>
                            <div class="fi-field">
                                <dt class="fi-field-label">Verified by</dt>
                                <dd class="fi-field-value"><?= fiOr($claim['nama_admin'] ?? '', 'Not assigned') ?></dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Evidence comparison -->
                    <section class="fi-panel" aria-labelledby="compare-heading">
                        <p class="fi-panel-kicker">01 / Verification</p>
                        <h2 id="compare-heading" class="fi-panel-title">Compare the evidence</h2>

                        <div class="fi-compare">
                            <div class="fi-compare-col fi-compare-col--claim">
                                <h3 class="fi-compare-title">Claimant's answers</h3>

                                <div class="fi-field">
                                    <span class="fi-field-label">Where the item was lost</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['lokasi_kehilangan'] ?? '')) ?></p>
                                </div>
                                <div class="fi-field">
                                    <span class="fi-field-label">Last seen at</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['terakhir_di_lihat'] ?? '')) ?></p>
                                </div>
                                <div class="fi-field">
                                    <span class="fi-field-label">Distinctive features</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['ciri_khusus_claim'] ?? '')) ?></p>
                                </div>
                            </div>

                            <div class="fi-compare-col fi-compare-col--item">
                                <h3 class="fi-compare-title">Original report</h3>

                                <div class="fi-field">
                                    <span class="fi-field-label">Location found</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['detail_lokasi'] ?? '', 'Not specified')) ?></p>
                                </div>
                                <div class="fi-field">
                                    <span class="fi-field-label">General features</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['ciri_umum'] ?? '')) ?></p>
                                </div>
                                <div class="fi-field">
                                    <span class="fi-field-label">Distinctive features (private)</span>
                                    <p class="fi-field-value"><?= nl2br(fiOr($claim['ciri_khusus'] ?? '')) ?></p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Reported item -->
                    <section class="fi-panel" aria-labelledby="item-heading">
                        <p class="fi-panel-kicker">02 / Reported item</p>
                        <h2 id="item-heading" class="fi-panel-title">
                            <?= fiOr($claim['nama_barang'] ?? '', 'Item unavailable') ?>
                        </h2>

                        <dl class="fi-fields mb-0">
                            <div class="fi-field">
                                <dt class="fi-field-label">Report ID</dt>
                                <dd class="fi-field-value">
                                    <?= $claim['kode_laporan'] !== null
                                        ? '#' . (int) $claim['kode_laporan']
                                        : 'No linked item' ?>
                                </dd>
                            </div>
                            <div class="fi-field">
                                <dt class="fi-field-label">Item status</dt>
                                <dd class="fi-field-value"><?php fiBadge($claim['status_barang'] ?? '', 'Unknown'); ?></dd>
                            </div>
                            <div class="fi-field">
                                <dt class="fi-field-label">Date found</dt>
                                <dd class="fi-field-value"><?= fiDate($claim['tanggal_ditemukan'] ?? '') ?></dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Claimant -->
                    <section class="fi-panel" aria-labelledby="claimant-heading">
                        <p class="fi-panel-kicker">03 / Claimant</p>
                        <h2 id="claimant-heading" class="fi-panel-title">
                            <?= fiOr($claim['name_user'] ?? '', 'Unknown student') ?>
                        </h2>

                        <dl class="fi-fields mb-0">
                            <div class="fi-field">
                                <dt class="fi-field-label">Campus email</dt>
                                <dd class="fi-field-value"><?= fiOr($claim['email_kampus'] ?? '', 'Not available') ?></dd>
                            </div>
                            <div class="fi-field">
                                <dt class="fi-field-label">Phone number</dt>
                                <dd class="fi-field-value"><?= fiOr($claim['no_telp'] ?? '', 'Not available') ?></dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <aside class="fi-sticky" aria-label="Decision">

                    <?php if ($isPending): ?>

                        <section class="fi-panel">
                            <p class="fi-panel-kicker">04 / Admin decision</p>
                            <h2 class="h4 mb-2">Review this claim</h2>
                            <p class="text-secondary small">
                                Check the evidence before deciding. A decision cannot be
                                changed through this form afterward.
                            </p>

                            <form
                                method="POST"
                                action="claim-detail.php?id=<?= (int) $claim['id_claim'] ?>"
                                class="needs-validation"
                                novalidate>
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= fiE(generateCsrfToken()) ?>">

                                <div class="mb-3">
                                    <label for="catatan_admin" class="form-label">
                                        Admin note / decision reason<span class="fi-required" aria-hidden="true">*</span>
                                    </label>
                                    <textarea
                                        class="form-control"
                                        id="catatan_admin"
                                        name="catatan_admin"
                                        rows="4"
                                        maxlength="5000"
                                        data-counter
                                        required><?= fiE(isset($_POST['catatan_admin']) && is_string($_POST['catatan_admin']) ? $_POST['catatan_admin'] : '') ?></textarea>
                                    <div class="invalid-feedback">Please explain your decision.</div>
                                    <div class="form-text">
                                        Explain why the claim is approved or rejected.
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label for="lokasi_pengambilan" class="form-label">Pickup location</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="lokasi_pengambilan"
                                        name="lokasi_pengambilan"
                                        maxlength="100"
                                        data-required-for="approve"
                                        placeholder="Example: IT Building, Administration Office"
                                        value="<?= fiE(isset($_POST['lokasi_pengambilan']) && is_string($_POST['lokasi_pengambilan']) ? $_POST['lokasi_pengambilan'] : '') ?>">
                                    <div class="invalid-feedback">
                                        Enter a pickup location before approving.
                                    </div>
                                    <div class="form-text">
                                        Required when approving. Not needed when rejecting.
                                    </div>
                                </div>

                                <div class="fi-decision-actions">
                                    <button
                                        type="submit"
                                        name="decision"
                                        value="approve"
                                        class="btn btn-primary"
                                        data-confirm="Approve this claim? A pickup code will be generated for the student.">
                                        Approve claim
                                    </button>
                                    <button
                                        type="submit"
                                        name="decision"
                                        value="reject"
                                        class="btn btn-outline-danger"
                                        data-confirm="Reject this claim? This cannot be undone from this form.">
                                        Reject claim
                                    </button>
                                </div>
                            </form>
                        </section>

                    <?php else: ?>

                        <section class="fi-panel">
                            <p class="fi-panel-kicker">04 / Decision completed</p>
                            <h2 class="h4 mb-3">This claim has been reviewed</h2>

                            <p class="mb-3">
                                Current status: <?php fiBadge($claim['status_klaim'] ?? ''); ?>
                            </p>

                            <dl class="fi-fields fi-fields--single mb-3">
                                <div class="fi-field">
                                    <dt class="fi-field-label">Admin note</dt>
                                    <dd class="fi-field-value">
                                        <?= nl2br(fiOr($claim['catatan_admin'] ?? '', 'No admin note')) ?>
                                    </dd>
                                </div>
                                <div class="fi-field">
                                    <dt class="fi-field-label">Pickup code</dt>
                                    <dd class="fi-field-value">
                                        <?php if (!empty($claim['kode_pengambilan'])): ?>
                                            <span class="fi-code"><?= fiE($claim['kode_pengambilan']) ?></span>
                                        <?php else: ?>
                                            Not generated
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="fi-field">
                                    <dt class="fi-field-label">Pickup location</dt>
                                    <dd class="fi-field-value">
                                        <?= fiOr($claim['lokasi_pengambilan'] ?? '', 'Not assigned') ?>
                                    </dd>
                                </div>
                            </dl>

                            <p class="small mb-0">
                                This claim can no longer be approved or rejected using this form.
                            </p>
                        </section>

                    <?php endif; ?>

                    <a href="claims.php" class="btn btn-outline-secondary w-100 mt-3">Back to claims</a>
                </aside>

            </div>

        <?php endif; ?>

    </div>
</main>

<?php fiFooter('Admin claim review'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
