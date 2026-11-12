<?php
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/backup_ui.php';

// Admin only. Does not need the database, so it still opens when the database is gone.
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        fiFlash('danger', 'The form expired. Please try again.');
    } else {
        // A backup can take a while; do not stop it if the browser tab is closed.
        @set_time_limit(0);
        ignore_user_abort(true);
        try {
            $result = BackupManager::run('manual', ['trigger' => 'dashboard', 'by' => fiAdminLabel()]);
            $status = (string) ($result['status'] ?? 'FAILED');
            if ($status === 'BUSY') {
                fiFlash('warning', 'Another backup or restore is running. Try again in a moment.');
            } elseif ($status === 'SUCCESS') {
                fiFlash('success', 'Backup finished: stored on this computer and on the remote copy.');
            } elseif ($status === 'PARTIAL_SUCCESS') {
                fiFlash('warning', 'Backup saved on this computer, but the remote copy is not complete. See Backup History.');
            } else {
                fiFlash('danger', 'The backup failed. See Backup History for the reason.');
            }
        } catch (Throwable $e) {
            error_log('[FindIT] Backup Now failed: ' . $e->getMessage());
            fiFlash('danger', 'The backup could not be started. Check logs/backup.log.');
        }
    }
    header('Location: backups.php');
    exit;
}

$flash = fiFlash();
$s = BackupManager::summary();
$last = $s['last_run'];

fiHead('Backup Management | FindIT Admin', '../public/assets');
fiAdminNav('backups');
?>
<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Backup',
            'Backup<br><span>management.</span>',
            'Keep a safe copy of the database on this computer and on a second location.',
            (string) $s['counts']['total'],
            'Backup files'
        );
        ?>

        <?php if ($flash): fiAlert($flash['type'], $flash['message'], true); endif; ?>
        <?php foreach ($s['warnings'] as $warning): fiAlert('warning', $warning); endforeach; ?>

        <section class="fi-stat-grid" aria-label="Backup summary">
            <div class="fi-stat fi-stat--lime">
                <span class="fi-stat-value"><?= $last ? fiWhen($last['finished_at'] ?? $last['started_at'] ?? null) : '–' ?></span>
                <span class="fi-stat-label">Last backup</span>
            </div>
            <div class="fi-stat">
                <span class="fi-stat-value"><?= $last ? fiBackupBadge($last['local']['status'] ?? '') : '–' ?></span>
                <span class="fi-stat-label">Local copy</span>
            </div>
            <div class="fi-stat fi-stat--lilac">
                <span class="fi-stat-value"><?= $last ? fiBackupBadge($last['remote']['status'] ?? '') : '–' ?></span>
                <span class="fi-stat-label">Remote copy</span>
            </div>
            <div class="fi-stat fi-stat--clay">
                <span class="fi-stat-value"><?= fiBytes($s['counts']['bytes']) ?></span>
                <span class="fi-stat-label"><?= (int) $s['counts']['daily'] ?> daily / <?= (int) $s['counts']['weekly'] ?> weekly</span>
            </div>
        </section>

        <section class="fi-action-grid" aria-label="Backup actions">
            <div class="fi-action-card">
                <h2>Process status</h2>
                <p>
                    <?php if ($s['running']): fiBackupBadge('RUNNING'); ?> A backup or restore is running now.
                    <?php elseif ($last): fiBackupBadge($last['status'] ?? ''); ?> Last run: <?= fiWhen($last['started_at'] ?? null) ?>
                        (<?= fiE((string) ($last['type'] ?? '')) ?>).
                    <?php else: ?>No backup has run yet.<?php endif; ?>
                </p>
                <p class="small text-muted mb-0">
                    Remote: <?= fiE((string) ($s['remote']['description'] ?? ($s['remote']['configured'] ? 'configured' : 'not configured'))) ?>
                </p>
            </div>

            <div class="fi-action-card">
                <h2>Backup now</h2>
                <p>Make a backup immediately. It is also made automatically by the Windows scheduled task.</p>
                <form method="post" action="backups.php">
                    <input type="hidden" name="csrf_token" value="<?= fiE(generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-primary" <?= $s['running'] ? 'disabled' : '' ?>
                        onclick="this.disabled=true;this.textContent='Backing up…';this.form.submit();">Backup Now</button>
                </form>
            </div>
        </section>
    </div>
</main>
<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
