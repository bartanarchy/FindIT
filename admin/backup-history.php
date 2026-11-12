<?php
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/backup_ui.php';

requireRole('admin');

$runs = BackupManager::history(100);

fiHead('Backup History | FindIT Admin', '../public/assets');
fiAdminNav('history');
?>
<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Backup',
            'Backup<br><span>history.</span>',
            'Every backup attempt, newest first.',
            (string) count($runs),
            'Recorded runs'
        );
        ?>
        <section aria-labelledby="history-heading">
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
</main>
<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
