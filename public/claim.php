
<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';

// only logged-in students can submit a claim.
requireRole('student');

function escapeClaimPage(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// read the report ID sent by item-detail.php.
$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

$errorMessage = '';
$successMessage = '';
$item = null;

// load the selected item before showing the claim form.
try {
    $sql = '
        SELECT
            i.kode_laporan,
            i.nama_barang,
            i.detail_lokasi,
            i.tanggal_ditemukan,
            i.status_barang,
            c.name_category
        FROM public.item AS i
        LEFT JOIN public.category AS c
            ON i.id_kategori = c.id_category
        WHERE i.kode_laporan = :item_id
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['item_id' => $itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        exit('The selected item could not be found.');
    }
} catch (PDOException $e) {
    error_log('[FindIT] Failed to load claim item: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load this item right now. Please try again later.');
}

// store form values so students do not have to retype everything after an error.
$formData = [
    'lokasi_kehilangan' => '',
    'terakhir_di_lihat' => '',
    'ciri_khusus_claim' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $field => $defaultValue) {
        $submittedValue = $_POST[$field] ?? '';
        $formData[$field] = is_string($submittedValue)
            ? trim($submittedValue)
            : '';
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    $submittedToken = is_string($submittedToken) ? $submittedToken : '';

    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Your form session has expired. Please refresh the page and try again.';
    } elseif (
        $formData['lokasi_kehilangan'] === '' ||
        $formData['terakhir_di_lihat'] === '' ||
        $formData['ciri_khusus_claim'] === ''
    ) {
        $errorMessage = 'Please complete all fields before submitting your claim.';
    } elseif (mb_strlen($formData['lokasi_kehilangan']) > 5000 ||
              mb_strlen($formData['terakhir_di_lihat']) > 5000 ||
              mb_strlen($formData['ciri_khusus_claim']) > 5000) {
        $errorMessage = 'Each answer must be no longer than 5,000 characters.';
    } elseif ($item['status_barang'] !== 'Ditemukan') {
        $errorMessage = 'This item is not currently available for claims.';
    } else {
        $user = currentUser();
        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            $errorMessage = 'Unable to identify your student account. Please sign in again.';
        } else {
            try {
                // avoid creating a second pending claim for the same item and student.
                $duplicateSql = '
                    SELECT id_claim
                    FROM public.claim
                    WHERE id_item = :item_id
                      AND id_user_pengaju = :user_id
                      AND status_klaim = :claim_status
                    LIMIT 1
                ';

                $duplicateStmt = $pdo->prepare($duplicateSql);
                $duplicateStmt->execute([
                    'item_id' => $item['kode_laporan'],
                    'user_id' => $userId,
                    'claim_status' => 'Menunggu Verifikasi',
                ]);

                if ($duplicateStmt->fetch()) {
                    $errorMessage = 'You already have a pending claim for this item.';
                } else {
                    // create a unique reference code for this claim request.
                    $claimCode = 'CLM-' . date('Ymd') . '-' .
                        strtoupper(bin2hex(random_bytes(4)));

                    $insertSql = '
                        INSERT INTO public.claim (
                            kode_klaim,
                            id_item,
                            id_user_pengaju,
                            lokasi_kehilangan,
                            terakhir_di_lihat,
                            ciri_khusus_claim,
                            status_klaim
                        ) VALUES (
                            :claim_code,
                            :item_id,
                            :user_id,
                            :lost_location,
                            :last_seen,
                            :distinctive_features,
                            :claim_status
                        )
                    ';

                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([
                        'claim_code' => $claimCode,
                        'item_id' => $item['kode_laporan'],
                        'user_id' => $userId,
                        'lost_location' => $formData['lokasi_kehilangan'],
                        'last_seen' => $formData['terakhir_di_lihat'],
                        'distinctive_features' => $formData['ciri_khusus_claim'],
                        'claim_status' => 'Menunggu Verifikasi',
                    ]);

                    // redirect after success to prevent duplicate form submissions.
                    $_SESSION['claim_success_message'] =
                        'Your claim has been submitted successfully. Reference: ' . $claimCode;

                    header('Location: claim.php?id=' . (int) $item['kode_laporan']);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('[FindIT] Failed to submit claim: ' . $e->getMessage());
                $errorMessage = 'We could not submit your claim right now. Please try again.';
            } catch (Throwable $e) {
                error_log('[FindIT] Claim processing error: ' . $e->getMessage());
                $errorMessage = 'Something went wrong while submitting your claim. Please try again.';
            }
        }
    }
}

if (!empty($_SESSION['claim_success_message'])) {
    $successMessage = (string) $_SESSION['claim_success_message'];
    unset($_SESSION['claim_success_message']);
}

$csrfToken = generateCsrfToken();

?>
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

$itemId = (int) $item['kode_laporan'];

fiHead('Claim item | FindIT');
fiPublicNav(currentUser(), 'home');
?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <div class="fi-narrow">

            <a href="item-detail.php?id=<?= $itemId ?>" class="fi-back-link">&larr; Back to item</a>

            <?php
            fiPageHeader(
                'Campus lost & found / Claim',
                'Claim this<br><span>item.</span>',
                'Tell us a few details that can help the admin verify that this item belongs to you.'
            );
            ?>

            <section class="fi-summary" aria-label="Item summary">
                <div>
                    <p class="fi-panel-kicker mb-1">Item report #<?= $itemId ?></p>
                    <h2 class="fi-summary-title"><?= fiE($item['nama_barang']) ?></h2>
                    <p class="fi-summary-meta">
                        <?= fiOr($item['name_category'] ?? '', 'Uncategorized') ?>
                        &middot; <?= fiOr($item['detail_lokasi'] ?? '', 'Location not specified') ?>
                        &middot; <?= fiDate($item['tanggal_ditemukan'] ?? '') ?>
                    </p>
                </div>
                <?php fiBadge($item['status_barang']); ?>
            </section>

            <?php if ($errorMessage !== ''): ?>
                <?php fiAlert('danger', $errorMessage); ?>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>

                <?php fiAlert('success', $successMessage); ?>

                <div class="d-flex flex-wrap gap-2">
                    <a href="my-claims.php" class="btn btn-primary">View my claims</a>
                    <a href="index.php" class="btn btn-outline-secondary">Back to found items</a>
                </div>

            <?php elseif ($item['status_barang'] !== 'Ditemukan'): ?>

                <?php fiAlert('warning', 'This item is not currently available for claims.'); ?>

                <a href="index.php" class="btn btn-outline-secondary">Browse other items</a>

            <?php else: ?>

                <form
                    method="POST"
                    action="claim.php?id=<?= $itemId ?>"
                    class="fi-form-card needs-validation"
                    novalidate>
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= fiE($csrfToken) ?>">

                    <div class="mb-4">
                        <label for="lokasi_kehilangan" class="form-label">
                            Where did you lose the item?<span class="fi-required" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            id="lokasi_kehilangan"
                            name="lokasi_kehilangan"
                            rows="3"
                            maxlength="5000"
                            data-counter
                            required><?= fiE($formData['lokasi_kehilangan']) ?></textarea>
                        <div class="invalid-feedback">Please describe where you think you lost it.</div>
                        <div class="form-text">
                            Describe the location where you think you lost it.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="terakhir_di_lihat" class="form-label">
                            When and where did you last see it?<span class="fi-required" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            id="terakhir_di_lihat"
                            name="terakhir_di_lihat"
                            rows="3"
                            maxlength="5000"
                            data-counter
                            required><?= fiE($formData['terakhir_di_lihat']) ?></textarea>
                        <div class="invalid-feedback">Please tell us when and where you last saw it.</div>
                    </div>

                    <div class="mb-4">
                        <label for="ciri_khusus_claim" class="form-label">
                            What makes this item yours?<span class="fi-required" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            id="ciri_khusus_claim"
                            name="ciri_khusus_claim"
                            rows="4"
                            maxlength="5000"
                            data-counter
                            required><?= fiE($formData['ciri_khusus_claim']) ?></textarea>
                        <div class="invalid-feedback">Please describe features only the owner would know.</div>
                        <div class="form-text">
                            Include details only the owner would know, such as a sticker,
                            scratch, case, or other distinctive feature.
                        </div>
                    </div>

                    <div class="fi-callout">
                        Your information will be reviewed by a FindIT administrator.
                        Submitting a claim does not guarantee approval.
                    </div>

                    <div class="fi-form-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            data-loading-text="Submitting...">
                            Submit claim
                        </button>
                        <a href="item-detail.php?id=<?= $itemId ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>

            <?php endif; ?>

        </div>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts('assets'); ?>
</body>
</html>
