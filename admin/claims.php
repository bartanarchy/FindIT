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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Claims | FindIT Admin</title>

    <link rel="stylesheet"
        href="../public/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet"
        href="../public/assets/css/style.css">
</head>

<body>
    <main class="fi-items-page">
        <div class="fi-container">

            <nav class="fi-items-topbar" aria-label="Admin navigation">
                <a href="index.php" class="fi-items-brand">
                    FindIT<span>.</span>
                </a>

                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="fi-items-topbar-note">Admin / Claims</span>

                    <a href="index.php" class="btn btn-outline-dark">
                        Dashboard
                    </a>

                    <a href="items.php" class="btn btn-outline-dark">
                        Manage items
                    </a>

                    <a href="../public/logout.php" class="btn btn-dark">
                        Sign out
                    </a>
                </div>
            </nav>

            <header class="fi-items-intro">
                <div>
                    <p class="fi-items-kicker">Campus lost & found / 02</p>

                    <h1 class="fi-items-title">
                        Manage<br>
                        <span>claims.</span>
                    </h1>

                    <p class="fi-items-description">
                        Review student claim requests and check the information
                        submitted for each reported item.
                    </p>
                </div>

                <div class="fi-items-total" aria-label="Total claim requests">
                    <span class="fi-items-total-number">
                        <?= number_format($totalClaims) ?>
                    </span>
                    <span class="fi-items-total-label">
                        Total claims
                    </span>
                </div>
            </header>

            <section aria-labelledby="claims-heading">
                <p class="fi-items-kicker mb-2">Claim management</p>
                <h2 id="claims-heading" class="h4 mb-3">Claim requests</h2>

                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= escapeClaimHtml($errorMessage) ?>
                    </div>

                <?php elseif ($totalClaims === 0): ?>
                    <section class="fi-items-table-panel">
                        <div class="fi-items-empty">
                            <div class="fi-items-empty-mark" aria-hidden="true">
                                +
                            </div>

                            <h3>No claim requests yet</h3>

                            <p>
                                Student claim requests will appear here after
                                they submit a claim for an item.
                            </p>
                        </div>
                    </section>

                <?php else: ?>
                    <div class="fi-items-toolbar">
                        <div class="fi-items-field">
                            <label for="claim-search">Search claims</label>
                            <input
                                type="search"
                                class="form-control"
                                id="claim-search"
                                placeholder="Claim code, item or student..."
                                autocomplete="off">
                        </div>

                        <div class="fi-items-field">
                            <label for="claim-status-filter">Claim status</label>
                            <select class="form-select" id="claim-status-filter">
                                <option value="">All statuses</option>

                                <?php
                                $claimStatuses = [];

                                foreach ($claims as $claim) {
                                    $status = trim((string) ($claim['status_klaim'] ?? ''));

                                    if ($status !== '') {
                                        $claimStatuses[$status] = $status;
                                    }
                                }

                                natcasesort($claimStatuses);
                                ?>

                                <?php foreach ($claimStatuses as $status): ?>
                                    <option value="<?= escapeClaimHtml($status) ?>">
                                        <?= escapeClaimHtml($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fi-items-results" aria-live="polite">
                        <p>
                            Showing <strong id="claim-visible-count"><?= $totalClaims ?></strong>
                            of <?= number_format($totalClaims) ?> claims
                        </p>

                        <button type="button"
                            class="btn btn-sm btn-outline-dark"
                            id="reset-claim-filters">
                            Reset filters
                        </button>
                    </div>

                    <div class="fi-items-table-panel">
                        <div class="fi-items-table-wrap">
                            <table class="table fi-items-table">
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
                                            $claimStatus
                                        ]));
                                        ?>

                                        <tr
                                            data-search="<?= escapeClaimHtml($searchText) ?>"
                                            data-status="<?= escapeClaimHtml(strtolower($claimStatus)) ?>">
                                            <td>
                                                <span class="fi-item-name">
                                                    <?= escapeClaimHtml($claimCode !== '' ? $claimCode : 'Claim #' . $claim['id_claim']) ?>
                                                </span>
                                                <span class="fi-item-meta">
                                                    Internal ID: #<?= (int) $claim['id_claim'] ?>
                                                </span>
                                            </td>

                                            <td>
                                                <span class="fi-item-name">
                                                    <?= escapeClaimHtml($itemName !== '' ? $itemName : 'Item unavailable') ?>
                                                </span>
                                                <span class="fi-item-meta">
                                                    <?php if ($claim['kode_laporan'] !== null): ?>
                                                        Report #<?= (int) $claim['kode_laporan'] ?>
                                                    <?php else: ?>
                                                        No linked item
                                                    <?php endif; ?>
                                                </span>
                                            </td>

                                            <td>
                                                <span class="fi-item-name">
                                                    <?= escapeClaimHtml($studentName !== '' ? $studentName : 'Unknown student') ?>
                                                </span>
                                                <span class="fi-item-meta">
                                                    <?= escapeClaimHtml($studentEmail) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= escapeClaimHtml($submittedAt !== '' ? $submittedAt : 'Not available') ?>
                                            </td>

                                            <td>
                                                <span class="fi-item-status">
                                                    <?= escapeClaimHtml($claimStatus !== '' ? $claimStatus : 'Not set') ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= escapeClaimHtml($pickupCode !== '' ? $pickupCode : '—') ?>
                                            </td>
                                            <td>
                                                <a
                                                    href="claim-detail.php?id=<?= (int) $claim['id_claim'] ?>"
                                                    class="btn btn-sm btn-outline-dark">
                                                    View details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <tr id="no-claim-results" hidden>
                                        <td colspan="6">
                                            <div class="fi-items-empty">
                                                <h3>No matching claims</h3>
                                                <p>
                                                    Try another search term or reset the filters.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="fi-items-footer">
                <span>FindIT · Campus Lost & Found</span>
                <span>Claim requests / Admin workspace</span>
            </footer>

        </div>
    </main>

    <script src="../public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../public/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

                claimRows.forEach(function(row) {
                    const searchText = row.dataset.search || '';
                    const status = row.dataset.status || '';

                    const matchesSearch = searchText.includes(searchTerm);
                    const matchesStatus =
                        selectedStatus === '' || status === selectedStatus;

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

            resetButton.addEventListener('click', function() {
                searchInput.value = '';
                statusFilter.value = '';
                filterClaims();
                searchInput.focus();
            });
        });
    </script>
</body>

</html>