<?php
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/backup_ui.php';

// Admin only. Does not need the database, so it still opens when the database is gone.
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'backup') === 'backup') {
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
                fiFlash('warning', 'Backup saved on this computer, but the remote copy is not complete. See the history below.');
            } else {
                fiFlash('danger', 'The backup failed. See the history below for the reason.');
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore' && $selected === null) {
        $result = ['status' => 'FAILED', 'error' => 'That backup file is not in the list any more. Reload the page and choose it again.', 'steps' => []];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore' && $selected !== null) {
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

$runs = BackupManager::history(100);
$state = RestoreManager::targetState();
$lastRestore = RestoreManager::lastRestore();
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
            'Back up the database, restore it from a backup, and review every run.',
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
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn btn-primary" <?= $s['running'] ? 'disabled' : '' ?>
                        onclick="this.disabled=true;this.textContent='Backing up…';this.form.submit();">Backup Now</button>
                </form>
            </div>
        </section>

        <?php if ($result): ?>
            <?php if (($result['status'] ?? '') === 'SUCCESS'): ?>
                <?php fiAlert('success', 'The database "' . $dbName . '" was restored and checked. The website can use it again.'
                    . (!empty($result['old_database']) ? ' The previous database was kept as "' . $result['old_database'] . '".' : '')); ?>
            <?php else: ?>
                <?php fiAlert('danger', (string) ($result['error'] ?? 'The restore failed. Nothing was replaced.')); ?>
            <?php endif; ?>
            <div class="fi-table-panel p-3 mb-4">
                <h2 class="h5 mb-3">Restore result</h2>
                <ol class="list-unstyled mb-0">
                    <?php foreach (($result['steps'] ?? []) as $i => $step): ?>
                        <li class="mb-2">
                            <?php fiBackupBadge($step['status'] === 'OK' ? 'SUCCESS' : ($step['status'] === 'SKIPPED' ? 'SKIPPED' : 'FAILED')); ?>
                            <strong><?= (int) $i + 1 ?>. <?= fiE((string) $step['name']) ?></strong>
                            <?php if (($step['detail'] ?? '') !== ''): ?><br><small class="text-muted"><?= fiE((string) $step['detail']) ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (($result['status'] ?? '') === 'SUCCESS'): ?>
                    <a class="btn btn-primary mt-3" href="../public/index.php">Open the website</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($state['state'] === 'MISSING'): ?>
            <?php fiAlert('warning', 'The database "' . $dbName . '" does not exist right now, so the website is offline. Choose a backup under "Restore data" below: it will create "' . $dbName . '" again with the same settings from .env.'); ?>
        <?php endif; ?>

        <section class="fi-action-grid" aria-label="Database state">
            <div class="fi-action-card">
                <h2>Database "<?= fiE($dbName) ?>"</h2>
                <p><?php fiBackupBadge($state['state']); ?> <?= fiE((string) $state['message']) ?></p>
                <?php if ($lastRestore): ?>
                    <p class="small text-muted mb-0">Last restore: <?= fiWhen($lastRestore['finished_at'] ?? null) ?> (<?= fiE((string) ($lastRestore['status'] ?? '')) ?>)</p>
                <?php endif; ?>
            </div>
            <div class="fi-action-card">
                <h2>If the website cannot open</h2>
                <p>Run this on the server computer. It works even when the database is missing:</p>
                <p><code>php scripts\restore.php --list</code><br><code>php scripts\restore.php --file=daily/&lt;file&gt;</code></p>
            </div>
        </section>

        <section aria-labelledby="flow-heading" class="mb-4">
            <div class="fi-table-panel p-3">
                <h2 id="flow-heading" class="h5 mb-3">How a restore works</h2>
                <ol class="mb-0">
                    <li>Pick a backup file (from this computer, or from the remote copy if the local one is gone).</li>
                    <li>FindIT reads the database name (<strong><?= fiE($dbName) ?></strong>) from <code>.env</code> and connects to the PostgreSQL maintenance database, not to <?= fiE($dbName) ?>, which may be deleted.</li>
                    <li>The file is checked: checksum and the 7 required tables.</li>
                    <li>A new empty database is created (UTF-8), so nothing existing is touched.</li>
                    <li>The backup is loaded into it, then tables, rows and relations are checked.</li>
                    <li>The website account is given access to the restored tables.</li>
                    <li>The new database is put in place as <strong><?= fiE($dbName) ?></strong>. If an old one existed it is renamed, not deleted.</li>
                    <li>The result of every step is shown here and written to the restore log.</li>
                </ol>
            </div>
        </section>

        <?php if ($selected !== null && $webEnabled && ($result['status'] ?? '') !== 'SUCCESS'): ?>
            <section aria-labelledby="confirm-heading">
                <div class="fi-section-head"><div><p class="fi-eyebrow mb-1">Confirm</p><h2 id="confirm-heading">Restore <?= fiE($selected['name']) ?></h2></div></div>
                <?php fiAlert('warning', 'The current database will be replaced by this backup. Data entered after ' . fiWhen(date('c', $selected['mtime'])) . ' will not be in it. The current database is kept under another name, not deleted.'); ?>
                <form method="post" action="backups.php#restore" class="fi-table-panel p-3" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= fiE(generateCsrfToken()) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="kind" value="<?= fiE($selected['kind']) ?>">
                    <input type="hidden" name="name" value="<?= fiE($selected['name']) ?>">
                    <input type="hidden" name="source" value="<?= fiE($selected['source']) ?>">
                    <label for="confirm" class="form-label">Type the database name <strong><?= fiE($dbName) ?></strong> to confirm</label>
                    <input class="form-control mb-3" id="confirm" name="confirm" required>
                    <button type="submit" class="btn btn-primary"
                        onclick="if(this.form.checkValidity()){this.disabled=true;this.textContent='Restoring…';this.form.submit();}">Restore now</button>
                    <a class="btn btn-outline-secondary" href="backups.php#restore">Cancel</a>
                </form>
            </section>
        <?php endif; ?>

        <section id="restore" aria-labelledby="files-heading">
            <div class="fi-section-head"><div><p class="fi-eyebrow mb-1">Backups</p><h2 id="files-heading">Restore data</h2></div></div>
            <?php if (!$webEnabled): ?>
                <?php fiAlert('info', 'Restoring from this page is switched off (RESTORE_WEB_ENABLED=false). Use the command line, or enable it in .env.'); ?>
            <?php endif; ?>
            <?php if (!$candidates): ?>
                <div class="fi-empty-boxed"><?php fiEmptyState('No backup files', 'Press "Backup Now" above to make the first one.'); ?></div>
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
                                        <a class="btn btn-sm btn-outline-secondary" href="backups.php?restore=1&<?= fiE(http_build_query(['kind' => $c['kind'], 'name' => $c['name'], 'source' => $c['source']])) ?>">Restore</a>
                                    <?php else: ?>–<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
            <?php endif; ?>
        </section>

        <section id="history" aria-labelledby="history-heading">
            <div class="fi-section-head">
                <div><p class="fi-eyebrow mb-1">Log</p><h2 id="history-heading">Runs</h2></div>
            </div>
            <?php if (!$runs): ?>
                <div class="fi-empty-boxed">
                    <?php fiEmptyState('No backups yet', 'Press "Backup Now" on the Backup Management page.'); ?>
                </div>
            <?php else: ?>
                <div class="fi-table-panel">
                    <div class="fi-table-wrap">
                        <table class="table fi-table">
                            <thead>
                                <tr>
                                    <th scope="col">File</th>
                                    <th scope="col">Time</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Result</th>
                                    <th scope="col">Validation</th>
                                    <th scope="col">Remote</th>
                                    <th scope="col">Restore</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($runs as $r): ?>
                                    <?php $restores = $r['restores'] ?? []; ?>
                                    <tr>
                                        <td data-label="File">
                                            <span class="fi-cell-name"><?= fiE(basename((string) ($r['file'] ?? '')) ?: '–') ?></span>
                                            <?php if (!empty($r['error'])): ?>
                                                <br><small class="text-muted"><?= fiE((string) $r['error']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Time"><?= fiWhen($r['started_at'] ?? null) ?></td>
                                        <td data-label="Type"><?= fiE((string) ($r['type'] ?? '')) ?></td>
                                        <td data-label="Size"><?= isset($r['size']) ? fiBytes($r['size']) : '–' ?></td>
                                        <td data-label="Result"><?php fiBackupBadge($r['status'] ?? ''); ?></td>
                                        <td data-label="Validation"><?php fiBackupBadge($r['validation']['status'] ?? 'NOT_RUN'); ?></td>
                                        <td data-label="Remote"><?php fiBackupBadge($r['remote']['status'] ?? ''); ?></td>
                                        <td data-label="Restore">
                                            <?= $restores ? 'Restored ' . fiWhen((string) end($restores)['at']) : '–' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
    </div>
</main>
<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
