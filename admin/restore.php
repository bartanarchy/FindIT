<?php
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/backup_ui.php';

// Admin only. Restoring from the website is OFF unless RESTORE_WEB_ENABLED=true in .env.
// The command line (php scripts/restore.php) always works, even when the database is gone.
requireRole('admin');

$webEnabled = BackupConfig::bool('RESTORE_WEB_ENABLED', false);
$dbName = BackupConfig::dbName();
$result = null;
$selected = null;

$candidates = RestoreManager::candidates();

// Only a file from our own list can be chosen; never a path typed by the user.
$find = static function (string $kind, string $name, string $source) use ($candidates): ?array {
    foreach ($candidates as $c) {
        if ($c['kind'] === $kind && $c['name'] === $name && $c['source'] === $source) {
            return $c;
        }
    }
    return null;
};

if ($webEnabled) {
    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    if (isset($src['name'], $src['kind'], $src['source'])) {
        $selected = $find((string) $src['kind'], (string) $src['name'], (string) $src['source']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected !== null) {
        if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            $result = ['status' => 'FAILED', 'error' => 'The form expired. Please try again.'];
        } else {
            @set_time_limit(0);
            ignore_user_abort(true);
            $result = RestoreManager::restoreMain([
                'kind' => $selected['kind'],
                'name' => $selected['name'],
                'source' => $selected['source'],
                'by' => fiAdminLabel(),
                'confirm' => (string) ($_POST['confirm'] ?? ''),
            ]);
        }
    }
}

$state = RestoreManager::targetState();
$last = RestoreManager::lastRestore();

fiHead('Restore Data | FindIT Admin', '../public/assets');
fiAdminNav('restore');
?>
<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Backup',
            'Restore<br><span>data.</span>',
            'Bring the database back from a backup file.',
            (string) count($candidates),
            'Restorable files'
        );
        ?>

        <?php if ($result): ?>
            <?php if (($result['status'] ?? '') === 'SUCCESS'): ?>
                <?php fiAlert('success', 'The database was restored. The previous database was kept as "' . ($result['old_database'] ?? 'none') . '".'); ?>
                <?php $state = RestoreManager::targetState(); ?>
            <?php else: ?>
                <?php fiAlert('danger', (string) ($result['error'] ?? 'The restore failed. Nothing was replaced.')); ?>
            <?php endif; ?>
        <?php endif; ?>

        <section class="fi-action-grid" aria-label="Database state">
            <div class="fi-action-card">
                <h2>Database "<?= fiE($dbName) ?>"</h2>
                <p><?php fiBackupBadge($state['state']); ?> <?= fiE((string) $state['message']) ?></p>
                <?php if ($last): ?>
                    <p class="small text-muted mb-0">Last restore: <?= fiWhen($last['finished_at'] ?? null) ?> (<?= fiE((string) ($last['status'] ?? '')) ?>)</p>
                <?php endif; ?>
            </div>
            <div class="fi-action-card">
                <h2>If the website cannot open</h2>
                <p>Run this on the server computer. It works even when the database is missing:</p>
                <p><code>php scripts\restore.php --list</code><br><code>php scripts\restore.php --file=daily/&lt;file&gt;</code></p>
            </div>
        </section>

        <?php if ($selected !== null && $webEnabled && ($result['status'] ?? '') !== 'SUCCESS'): ?>
            <section aria-labelledby="confirm-heading">
                <div class="fi-section-head"><div><p class="fi-eyebrow mb-1">Confirm</p><h2 id="confirm-heading">Restore <?= fiE($selected['name']) ?></h2></div></div>
                <?php fiAlert('warning', 'The current database will be replaced by this backup. Data entered after ' . fiWhen(date('c', $selected['mtime'])) . ' will not be in it. The current database is kept under another name, not deleted.'); ?>
                <form method="post" action="restore.php" class="fi-table-panel p-3" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= fiE(generateCsrfToken()) ?>">
                    <input type="hidden" name="kind" value="<?= fiE($selected['kind']) ?>">
                    <input type="hidden" name="name" value="<?= fiE($selected['name']) ?>">
                    <input type="hidden" name="source" value="<?= fiE($selected['source']) ?>">
                    <label for="confirm" class="form-label">Type the database name <strong><?= fiE($dbName) ?></strong> to confirm</label>
                    <input class="form-control mb-3" id="confirm" name="confirm" required>
                    <button type="submit" class="btn btn-primary"
                        onclick="if(this.form.checkValidity()){this.disabled=true;this.textContent='Restoring…';this.form.submit();}">Restore now</button>
                    <a class="btn btn-outline-secondary" href="restore.php">Cancel</a>
                </form>
            </section>
        <?php endif; ?>

        <section aria-labelledby="files-heading">
            <div class="fi-section-head"><div><p class="fi-eyebrow mb-1">Backups</p><h2 id="files-heading">Available files</h2></div></div>
            <?php if (!$webEnabled): ?>
                <?php fiAlert('info', 'Restoring from this page is switched off (RESTORE_WEB_ENABLED=false). Use the command line, or enable it in .env.'); ?>
            <?php endif; ?>
            <?php if (!$candidates): ?>
                <div class="fi-empty-boxed"><?php fiEmptyState('No backup files', 'Make a backup first on the Backup Management page.'); ?></div>
            <?php else: ?>
                <div class="fi-table-panel"><div class="fi-table-wrap">
                    <table class="table fi-table">
                        <thead><tr>
                            <th scope="col">File</th><th scope="col">Time</th><th scope="col">Where</th>
                            <th scope="col">Size</th><th scope="col">Validation</th><th scope="col">Action</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($candidates as $c): ?>
                            <tr>
                                <td data-label="File"><span class="fi-cell-name"><?= fiE($c['kind'] . '/' . $c['name']) ?></span></td>
                                <td data-label="Time"><?= fiWhen(date('c', (int) $c['mtime'])) ?></td>
                                <td data-label="Where"><?= $c['source'] === 'remote' ? 'Remote only' : 'This computer' ?></td>
                                <td data-label="Size"><?= fiBytes($c['size']) ?></td>
                                <td data-label="Validation"><?php fiBackupBadge($c['validation']); ?></td>
                                <td data-label="Action">
                                    <?php if ($webEnabled): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="restore.php?<?= fiE(http_build_query(['kind' => $c['kind'], 'name' => $c['name'], 'source' => $c['source']])) ?>">Restore</a>
                                    <?php else: ?>–<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
