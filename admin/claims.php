<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';

// only admins can access this page.
requireRole('admin');

// escape database values before displaying them in HTML.
function escapeClaimHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$claims = [];
$errorMessage = '';

try {
    // get claim requests together with the related item and student.
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
            u.name_user,
            u.email_kampus
        FROM public.claim AS c
        LEFT JOIN public.item AS i
            ON c.id_item = i.kode_laporan
        LEFT JOIN public."User" AS u
            ON c.id_user_pengaju = u.id_user
        ORDER BY c.created_at DESC NULLS LAST, c.id_claim DESC
    ';

    $stmt = $pdo->query($sql);
    $claims = $stmt->fetchAll();
} catch (PDOException $e) {
    // keep database details in the server log, not in the page.
    error_log('Manage Claims error: ' . $e->getMessage());
    $errorMessage = 'Unable to load claim requests. Please try again later.';
}

$totalClaims = count($claims);
?>
<?php

require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Count claims waiting for a decision so the admin sees what needs attention first.
$pendingCount = 0;

foreach ($claims as $row) {
    if (($row['status_klaim'] ?? '') === 'Menunggu Verifikasi') {
        $pendingCount++;
    }
}

fiHead('Manage claims | FindIT Admin', '../public/assets');
fiAdminNav('claims');
?>

<main class="fi-main" id="main">
    <div class="fi-container">

        <?php
        fiPageHeader(
            'Campus lost & found / 02',
            'Manage<br><span>claims.</span>',
            'Review student claim requests and check the information submitted for each reported item.',
            number_format($totalClaims),
            'Total claims'
        );
        ?>

        <section aria-labelledby="claims-heading">
            <div class="fi-section-head">
                <div>
                    <p class="fi-eyebrow mb-1">Claim management</p>
                    <h2 id="claims-heading">Claim requests</h2>
                </div>

                <?php if ($pendingCount > 0): ?>
                    <span class="fi-badge fi-badge--warning">
                        <?= $pendingCount ?> waiting for review
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($errorMessage !== ''): ?>

                <?php fiAlert('danger', $errorMessage); ?>

            <?php elseif ($totalClaims === 0): ?>

                <div class="fi-empty-boxed">
                    <?php
                    fiEmptyState(
                        'No claim requests yet',
                        'Student claim requests will appear here after they submit a claim for an item.'
                    );
                    ?>
                </div>

            <?php else: ?>

                <?php
                $claimStatuses = [];

                foreach ($claims as $row) {
                    $rowStatus = trim((string) ($row['status_klaim'] ?? ''));

                    if ($rowStatus !== '') {
                        $claimStatuses[$rowStatus] = $rowStatus;
                    }
                }

                natcasesort($claimStatuses);
                ?>

                <div class="fi-toolbar">
                    <div class="fi-toolbar-field">
                        <label for="claim-search">Search claims</label>
                        <input
                            type="search"
                            class="form-control"
                            id="claim-search"
                            placeholder="Claim code, item or student..."
                            autocomplete="off">
                    </div>

                    <div class="fi-toolbar-field">
                        <label for="claim-status-filter">Claim status</label>
                        <select class="form-select" id="claim-status-filter">
                            <option value="">All statuses</option>
                            <?php foreach ($claimStatuses as $statusOption): ?>
                                <option value="<?= fiE($statusOption) ?>"><?= fiE($statusOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="fi-results-bar" aria-live="polite">
                    <p>
                        Showing <strong id="claim-visible-count"><?= $totalClaims ?></strong>
                        of <?= number_format($totalClaims) ?> claims
                    </p>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="reset-claim-filters">
                        Reset filters
                    </button>
                </div>

                <div class="fi-table-panel">
                    <div class="fi-table-wrap">
                        <table class="table fi-table">
                            <thead>
                                <tr>
                                    <th scope="col">Claim</th>
                                    <th scope="col">Item</th>
                                    <th scope="col">Student</th>
                                    <th scope="col">Submitted</th>
                                    <th scope="col">Claim status</th>
                                    <th scope="col">Pickup code</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="claims-table-body">
                                <?php foreach ($claims as $claim): ?>
                                    <?php
                                    $claimCode = (string) ($claim['kode_klaim'] ?? '');
                                    $itemName = (string) ($claim['nama_barang'] ?? '');
                                    $studentName = (string) ($claim['name_user'] ?? '');
                                    $studentEmail = (string) ($claim['email_kampus'] ?? '');
                                    $claimStatus = (string) ($claim['status_klaim'] ?? '');
                                    $submittedAt = (string) ($claim['created_at'] ?? '');
                                    $pickupCode = (string) ($claim['kode_pengambilan'] ?? '');
                                    $searchText = strtolower(implode(' ', [
                                        (string) ($claim['id_claim'] ?? ''),
                                        $claimCode,
                                        $itemName,
                                        $studentName,
                                        $studentEmail,
                                        $claimStatus,
                                    ]));
                                    ?>
                                    <tr
                                        data-search="<?= fiE($searchText) ?>"
                                        data-status="<?= fiE(strtolower($claimStatus)) ?>">
                                        <td data-label="Claim">
                                            <span class="fi-cell-name">
                                                <?= fiE($claimCode !== '' ? $claimCode : 'Claim #' . $claim['id_claim']) ?>
                                            </span>
                                            <span class="fi-cell-meta">
                                                Internal ID: #<?= (int) $claim['id_claim'] ?>
                                            </span>
                                        </td>
                                        <td data-label="Item">
                                            <span class="fi-cell-name">
                                                <?= fiOr($itemName, 'Item unavailable') ?>
                                            </span>
                                            <span class="fi-cell-meta">
                                                <?= $claim['kode_laporan'] !== null
                                                    ? 'Report #' . (int) $claim['kode_laporan']
                                                    : 'No linked item' ?>
                                            </span>
                                        </td>
                                        <td data-label="Student">
                                            <span class="fi-cell-name">
                                                <?= fiOr($studentName, 'Unknown student') ?>
                                            </span>
                                            <span class="fi-cell-meta"><?= fiE($studentEmail) ?></span>
                                        </td>
                                        <td data-label="Submitted">
                                            <?= fiDateTime($submittedAt) ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php fiBadge($claimStatus); ?>
                                        </td>
                                        <td data-label="Pickup code">
                                            <?php if ($pickupCode !== ''): ?>
                                                <span class="fi-code"><?= fiE($pickupCode) ?></span>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action">
                                            <a
                                                href="claim-detail.php?id=<?= (int) $claim['id_claim'] ?>"
                                                class="btn btn-sm btn-outline-secondary">
                                                View details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <tr id="no-claim-results" hidden>
                                    <td colspan="7" data-label="">
                                        <?php
                                        fiEmptyState(
                                            'No matching claims',
                                            'Try another search term or reset the filters.',
                                            '',
                                            '?'
                                        );
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>
        </section>

    </div>
</main>

<?php fiFooter('Claim requests / Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('claim-search');
    const statusFilter = document.getElementById('claim-status-filter');
    const resetButton = document.getElementById('reset-claim-filters');
    const visibleCount = document.getElementById('claim-visible-count');
    const noResultsRow = document.getElementById('no-claim-results');
    const claimRows = Array.from(
        document.querySelectorAll('#claims-table-body tr[data-search]')
    );

    if (!searchInput || !statusFilter || !resetButton || !visibleCount) {
        return;
    }

    function filterClaims() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const selectedStatus = statusFilter.value.toLowerCase();
        let visibleClaims = 0;

        claimRows.forEach(function (row) {
            const searchText = row.dataset.search || '';
            const status = row.dataset.status || '';

            const matchesSearch = searchText.includes(searchTerm);
            const matchesStatus = selectedStatus === '' || status === selectedStatus;
            const shouldShow = matchesSearch && matchesStatus;

            row.hidden = !shouldShow;

            if (shouldShow) {
                visibleClaims++;
            }
        });

        visibleCount.textContent = visibleClaims;

        if (noResultsRow) {
            noResultsRow.hidden = visibleClaims !== 0;
        }
    }

    searchInput.addEventListener('input', filterClaims);
    statusFilter.addEventListener('change', filterClaims);

    resetButton.addEventListener('click', function () {
        searchInput.value = '';
        statusFilter.value = '';
        filterClaims();
        searchInput.focus();
    });
});
</script>
</body>
</html>
