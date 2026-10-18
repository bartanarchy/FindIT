
<?php

require_once dirname(__DIR__) . '/app/helpers/auth.php';

// only admin can access this dashboard
requireRole('admin');

$admin = currentUser();

function escapeAdminHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Load the numbers shown on the dashboard cards.
$stats = [
    'items' => null,
    'available' => null,
    'pending' => null,
    'approved' => null,
];
$statsError = false;

try {
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM public.item) AS items,
            (SELECT COUNT(*) FROM public.item WHERE status_barang = 'Ditemukan') AS available,
            (SELECT COUNT(*) FROM public.claim WHERE status_klaim = 'Menunggu Verifikasi') AS pending,
            (SELECT COUNT(*) FROM public.claim WHERE status_klaim = 'Disetujui') AS approved
    ")->fetch();

    if ($row) {
        $stats = array_map('intval', $row);
    }
} catch (PDOException $e) {
    error_log('[FindIT] Dashboard statistics error: ' . $e->getMessage());
    $statsError = true;
}

$showStat = static function ($value): string {
    return $value === null ? '&ndash;' : number_format((int) $value);
};

fiHead('Admin dashboard | FindIT', '../public/assets');
fiAdminNav('dashboard');
?>

<main class="fi-main" id="main">
    <div class="fi-container">

        <?php
        fiPageHeader(
            'Campus lost & found / Admin',
            'Admin<br><span>dashboard.</span>',
            'Welcome back, ' . ($admin['name'] ?? 'Administrator')
            . '. Manage reported items and review student claims.'
        );
        ?>

        <?php if ($statsError): ?>
            <?php fiAlert('warning', 'The statistics could not be loaded right now. You can still use the links below.'); ?>
        <?php endif; ?>

        <section class="fi-stat-grid" aria-label="Summary">
            <div class="fi-stat fi-stat--lime">
                <span class="fi-stat-value"><?= $showStat($stats['pending']) ?></span>
                <span class="fi-stat-label">Claims to review</span>
            </div>
            <div class="fi-stat">
                <span class="fi-stat-value"><?= $showStat($stats['available']) ?></span>
                <span class="fi-stat-label">Items available</span>
            </div>
            <div class="fi-stat fi-stat--lilac">
                <span class="fi-stat-value"><?= $showStat($stats['items']) ?></span>
                <span class="fi-stat-label">Total item reports</span>
            </div>
            <div class="fi-stat fi-stat--clay">
                <span class="fi-stat-value"><?= $showStat($stats['approved']) ?></span>
                <span class="fi-stat-label">Claims approved</span>
            </div>
        </section>

        <section class="fi-action-grid" aria-label="Admin shortcuts">
            <div class="fi-action-card">
                <h2>Manage items</h2>
                <p>Review, add, and edit items reported by students.</p>
                <a href="items.php" class="btn btn-primary">View items</a>
            </div>

            <div class="fi-action-card">
                <h2>Manage claims</h2>
                <p>
                    Review claim requests, compare the evidence with the original report,
                    and approve or reject.
                </p>
                <a href="claims.php" class="btn btn-primary">
                    View claims<?= !empty($stats['pending']) ? ' (' . (int) $stats['pending'] . ' waiting)' : '' ?>
                </a>
            </div>
        </section>

    </div>
</main>

<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
