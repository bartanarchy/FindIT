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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Details | FindIT Admin</title>
    <link rel="stylesheet" href="../public/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../public/assets/css/style.css">
</head>

<body>
    <main class="fi-items-page">
        <div class="fi-container">
            <nav class="fi-items-topbar" aria-label="Admin navigation">
                <a href="index.php" class="fi-items-brand">FindIT<span>.</span></a>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="claims.php" class="btn btn-outline-dark">Back to claims</a>
                    <a href="../public/logout.php" class="btn btn-dark">Sign out</a>
                </div>
            </nav>

            <header class="fi-items-intro">
                <div>
                    <p class="fi-items-kicker">Campus lost &amp; found / Claim review</p>
                    <h1 class="fi-items-title">Claim<br><span>details.</span></h1>
                    <p class="fi-items-description">Review the submitted information before making a decision.</p>
                </div>
                <?php if ($claim): ?>
                    <div class="fi-items-total">
                        <span class="fi-items-total-number">#<?= (int) $claim['id_claim'] ?></span>
                        <span class="fi-items-total-label">Internal claim ID</span>
                    </div>
                <?php endif; ?>
            </header>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= escapeClaimDetail($errorMessage) ?></div>
                <a href="claims.php" class="btn btn-outline-dark">Return to claims</a>
            <?php elseif ($claim): ?>
                <?php if ($actionMessage !== ''): ?>
                    <div class="alert alert-success" role="status"><?= escapeClaimDetail($actionMessage) ?></div>
                <?php endif; ?>
                <?php if ($actionError !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= escapeClaimDetail($actionError) ?></div>
                <?php endif; ?>

                <section class="fi-items-table-panel p-4 mb-4">
                    <p class="fi-items-kicker">Request overview</p>
                    <h2 class="h4 mb-4"><?= escapeClaimDetail($claim['kode_klaim'] ?? 'Claim #' . $claim['id_claim']) ?></h2>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Claim status</p>
                            <p class="mb-0 fw-bold"><?= escapeClaimDetail($claim['status_klaim'] ?? 'Not set') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Submitted</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['created_at'] ?? 'Not available') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Last updated</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['updated_at'] ?? 'Not available') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Verified by</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['nama_admin'] ?? 'Not assigned') ?></p>
                        </div>
                    </div>
                </section>

                <section class="fi-items-table-panel p-4 mb-4">
                    <p class="fi-items-kicker">01 / Reported item</p>
                    <h2 class="h4 mb-4"><?= escapeClaimDetail($claim['nama_barang'] ?? 'Item unavailable') ?></h2>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Report ID</p>
                            <p class="mb-0"><?= $claim['kode_laporan'] !== null ? '#' . (int) $claim['kode_laporan'] : 'No linked item' ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Item status</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['status_barang'] ?? 'Unknown') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Reported location</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['detail_lokasi'] ?? 'Not specified') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Date found</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['tanggal_ditemukan'] ?? 'Not specified') ?></p>
                        </div>
                    </div>
                </section>

                <section class="fi-items-table-panel p-4 mb-4">
                    <p class="fi-items-kicker">02 / Claimant</p>
                    <h2 class="h4 mb-4"><?= escapeClaimDetail($claim['name_user'] ?? 'Unknown student') ?></h2>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Campus email</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['email_kampus'] ?? 'Not available') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Phone number</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['no_telp'] ?? 'Not available') ?></p>
                        </div>
                    </div>
                </section>


                <section class="fi-items-table-panel p-4 mb-4">
                    <p class="fi-items-kicker">03 / Claim evidence</p>
                    <h2 class="h4 mb-4">Student's submitted information</h2>

                    <div class="mb-4">
                        <p class="fi-items-kicker mb-1">Where the item was lost</p>
                        <p class="mb-0">
                            <?= nl2br(escapeClaimDetail($claim['lokasi_kehilangan'] ?? 'Not provided')) ?>
                        </p>
                    </div>

                    <div class="mb-4">
                        <p class="fi-items-kicker mb-1">Last seen at</p>
                        <p class="mb-0">
                            <?= nl2br(escapeClaimDetail($claim['terakhir_di_lihat'] ?? 'Not provided')) ?>
                        </p>
                    </div>

                    <div class="mb-4">
                        <p class="fi-items-kicker mb-1">Claimant's distinctive features</p>
                        <p class="mb-0">
                            <?= nl2br(escapeClaimDetail($claim['ciri_khusus_claim'] ?? 'Not provided')) ?>
                        </p>
                    </div>

                    <hr class="my-4">

                    <p class="fi-items-kicker">Original item information</p>
                    <h3 class="h5 mb-4">Compare with the reported item</h3>

                    <div class="mb-4">
                        <p class="fi-items-kicker mb-1">Original general features</p>
                        <p class="mb-0">
                            <?= nl2br(escapeClaimDetail($claim['ciri_umum'] ?? 'Not provided')) ?>
                        </p>
                    </div>

                    <div>
                        <p class="fi-items-kicker mb-1">Original distinctive features</p>
                        <p class="mb-0">
                            <?= nl2br(escapeClaimDetail($claim['ciri_khusus'] ?? 'Not provided')) ?>
                        </p>
                    </div>
                </section>

                <section class="fi-items-table-panel p-4 mb-4">
                    <p class="fi-items-kicker">04 / Admin notes</p>
                    <h2 class="h4 mb-3">Verification and pickup</h2>
                    <div class="mb-4">
                        <p class="fi-items-kicker mb-1">Admin note</p>
                        <p class="mb-0"><?= nl2br(escapeClaimDetail($claim['catatan_admin'] ?? 'No admin note yet')) ?></p>
                    </div>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Pickup code</p>
                            <p class="mb-0 fw-bold"><?= escapeClaimDetail($claim['kode_pengambilan'] ?? 'Not generated') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="fi-items-kicker mb-1">Pickup location</p>
                            <p class="mb-0"><?= escapeClaimDetail($claim['lokasi_pengambilan'] ?? 'Not assigned') ?></p>
                        </div>
                    </div>
                </section>

                <?php if ($claim['status_klaim'] === 'Menunggu Verifikasi'): ?>
                    <section class="fi-items-table-panel p-4 mb-4">
                        <p class="fi-items-kicker">05 / Admin decision</p>
                        <h2 class="h4 mb-3">Review this claim.</h2>
                        <p class="text-secondary">Check the submitted evidence before approving or rejecting this request. A decision cannot be changed through this form afterward.</p>
                        <form method="POST" action="claim-detail.php?id=<?= (int) $claim['id_claim'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= escapeClaimDetail(generateCsrfToken()) ?>">
                            <div class="mb-3">
                                <label for="catatan_admin" class="form-label fw-semibold">Admin note / decision reason</label>
                                <textarea class="form-control" id="catatan_admin" name="catatan_admin" rows="4" maxlength="5000" required><?= escapeClaimDetail(isset($_POST['catatan_admin']) && is_string($_POST['catatan_admin']) ? $_POST['catatan_admin'] : '') ?></textarea>
                                <div class="form-text">Explain why the claim is approved or rejected.</div>
                            </div>
                            <div class="mb-4">
                                <label for="lokasi_pengambilan" class="form-label fw-semibold">Pickup location</label>
                                <input type="text" class="form-control" id="lokasi_pengambilan" name="lokasi_pengambilan" maxlength="100" placeholder="Example: IT Building, Administration Office" value="<?= escapeClaimDetail(isset($_POST['lokasi_pengambilan']) && is_string($_POST['lokasi_pengambilan']) ? $_POST['lokasi_pengambilan'] : '') ?>">
                                <div class="form-text">Required when approving a claim. Not required when rejecting it.</div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" name="decision" value="approve" class="btn btn-dark">Approve claim</button>
                                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">Reject claim</button>
                            </div>
                        </form>
                    </section>
                <?php else: ?>
                    <section class="fi-items-table-panel p-4 mb-4">
                        <p class="fi-items-kicker">05 / Decision completed</p>
                        <h2 class="h4 mb-2">This claim has been reviewed.</h2>
                        <p class="mb-0">Current status: <strong><?= escapeClaimDetail($claim['status_klaim']) ?></strong></p>
                        <p class="text-secondary small mt-2 mb-0">This claim can no longer be approved or rejected using this form.</p>
                    </section>
                <?php endif; ?>

                <a href="claims.php" class="btn btn-outline-dark">Back to claims</a>
            <?php endif; ?>

            <footer class="fi-items-footer">
                <span>FindIT · Campus Lost &amp; Found</span>
                <span>Admin claim review</span>
            </footer>
        </div>
    </main>
</body>

</html>