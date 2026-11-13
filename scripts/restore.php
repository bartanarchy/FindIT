<?php

// Restore the FindIT database from a backup. Works even when the database is gone,
// because it does not use the website's database connection.
//
//   php scripts/restore.php --status                     what state is the database in?
//   php scripts/restore.php --list                       backups that can be restored
//   php scripts/restore.php                              step by step (asks what to restore)
//   php scripts/restore.php --file=daily/findit_2026-11-12_020000.dump --confirm=<database name>
//   add --remote to take the file from the remote copy instead of this computer
//
// Your current database is never dropped: it is renamed to <name>_old_<time> and kept.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/app/backup/bootstrap.php';

$file = null;
$confirm = null;
$remote = false;
$mode = 'interactive';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--status') {
        $mode = 'status';
    } elseif ($arg === '--list') {
        $mode = 'list';
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif (preg_match('/^--file=(daily)\/(findit_[A-Za-z0-9_.-]+\.dump)$/', $arg, $m)) {
        $file = [$m[1], $m[2]];
        $mode = 'restore';
    } elseif (preg_match('/^--confirm=(.+)$/', $arg, $m)) {
        $confirm = $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "See the comment at the top of scripts/restore.php\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown or invalid option: $arg\n");
        exit(1);
    }
}

function show(string $line = ''): void
{
    echo $line . PHP_EOL;
}

function sizeText(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 1) . ' KB';
}

function printResult(array $rec): void
{
    show();
    foreach ($rec['steps'] as $step) {
        show(sprintf('  [%-7s] %s%s', $step['status'], $step['name'], $step['detail'] !== '' ? ' - ' . $step['detail'] : ''));
    }
    foreach ($rec['warnings'] as $warning) {
        show('  [WARNING] ' . $warning);
    }
    show();
}

$database = BackupConfig::dbName();
$state = RestoreManager::targetState();

if ($mode === 'status') {
    show('Database "' . $database . '": ' . $state['state'] . ' - ' . $state['message']);
    $last = RestoreManager::lastRestore();
    if ($last) {
        show('Last restore: ' . $last['status'] . ' - ' . RestoreManager::summaryLine($last));
    }
    exit(0);
}

$candidates = RestoreManager::candidates();

if ($mode === 'list') {
    if (!$candidates) {
        show('No backups found in ' . BackupConfig::baseDir());
        exit(1);
    }
    foreach ($candidates as $i => $c) {
        show(sprintf('%2d. %s/%s  %s  %s  [%s]%s', $i + 1, $c['kind'], $c['name'], date('Y-m-d H:i', $c['mtime']), sizeText($c['size']), $c['source'], $c['validation'] === 'FAILED' ? '  VALIDATION FAILED' : ''));
    }
    exit(0);
}

show('FindIT restore');
show('Database "' . $database . '" is: ' . $state['state'] . ' - ' . $state['message']);
if (in_array($state['state'], ['SERVER_DOWN', 'AUTH_FAILED', 'ERROR'], true)) {
    show();
    show('A restore needs a working PostgreSQL server and the restore account (RESTORE_DB_USER / RESTORE_DB_PASS).');
    show('Start the PostgreSQL service, or fix .env, and run this again.');
    exit(1);
}

if ($mode === 'interactive') {
    if (!$candidates) {
        show('No backups were found in ' . BackupConfig::baseDir() . ' (or on the remote copy).');
        exit(1);
    }

    show();
    show('Available backups (newest first):');
    foreach ($candidates as $i => $c) {
        show(sprintf('  %2d. %s/%s   %s   %s   [%s]%s', $i + 1, $c['kind'], $c['name'], date('Y-m-d H:i', $c['mtime']), sizeText($c['size']), $c['source'], $c['validation'] === 'FAILED' ? '  VALIDATION FAILED' : ''));
    }
    show();
    echo 'Number of the backup to restore (or press Enter to cancel): ';
    $choice = trim((string) fgets(STDIN));
    if ($choice === '' || !ctype_digit($choice) || !isset($candidates[(int) $choice - 1])) {
        show('Cancelled.');
        exit(1);
    }

    $picked = $candidates[(int) $choice - 1];
    $file = [$picked['kind'], $picked['name']];
    $remote = $picked['source'] === 'remote';

    show();
    show('This will replace the database "' . $database . '" with the backup from ' . date('Y-m-d H:i', $picked['mtime']) . '.');
    show('Your current database is kept (renamed), not deleted.');
    echo 'To continue, type the database name (' . $database . '): ';
    $confirm = trim((string) fgets(STDIN));
}

if ($file === null || $confirm === null) {
    fwrite(STDERR, "Give both --file=daily/<name>.dump and --confirm=<database name>.\n");
    exit(1);
}

$by = 'cli:' . (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'user') : (getenv('USERNAME') ?: 'user'));

show();
show('Restoring... (this can take a minute)');

$rec = RestoreManager::restoreMain([
    'kind' => $file[0],
    'name' => $file[1],
    'source' => $remote ? 'remote' : 'local',
    'by' => $by,
    'confirm' => $confirm,
]);

printResult($rec);

if ($rec['status'] === 'SUCCESS') {
    show('RESTORE SUCCESSFUL. The website can use the database again.');
    show('Status: ' . RestoreManager::summaryLine($rec));
    if ($rec['old_database']) {
        show('Drop the previous database yourself when you are sure it is not needed.');
    }
    exit(0);
}

show('RESTORE FAILED: ' . $rec['error']);
show('Your database was not replaced. Details: ' . BackupConfig::baseDir() . '/logs/restore.log');
exit(1);
