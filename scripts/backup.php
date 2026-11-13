<?php

// Makes a database backup. This is what Windows Task Scheduler runs.
//
//   php scripts/backup.php                    scheduled backup (daily)
//   php scripts/backup.php --type=manual      extra backup right now
//   php scripts/backup.php --force            ignore the "backup was made recently" rule
//   php scripts/backup.php --retry-remote     only retry copies that never reached the remote
//   php scripts/backup.php --retries=3 --retry-delay=60   wait for PostgreSQL to start (3 tries, 60 s apart)
//
// Exit code: 0 = ok (or skipped), 1 = failed, 2 = saved locally but remote copy missing,
//            3 = another backup/restore is already running.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/app/backup/bootstrap.php';

$type = 'auto';
$options = ['trigger' => 'task', 'force' => false, 'retries' => 1, 'retry_delay' => 30];
$retryOnly = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--type=(auto|daily|manual)$/', $arg, $m)) {
        $type = $m[1];
    } elseif ($arg === '--force') {
        $options['force'] = true;
    } elseif ($arg === '--retry-remote') {
        $retryOnly = true;
    } elseif (preg_match('/^--retries=(\d{1,2})$/', $arg, $m)) {
        $options['retries'] = max(1, (int) $m[1]);
    } elseif (preg_match('/^--retry-delay=(\d{1,3})$/', $arg, $m)) {
        $options['retry_delay'] = max(1, (int) $m[1]);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "See the comment at the top of scripts/backup.php\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(1);
    }
}

if ($type === 'manual') {
    $options['trigger'] = 'cli';
}

try {
    $result = $retryOnly ? BackupManager::retryRemoteOnly() : BackupManager::run($type, $options);
} catch (Throwable $e) {
    BackupStore::log('backup', 'error', 'cli_error', ['detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
    fwrite(STDERR, 'Backup could not start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo $result['status'] . ': ' . $result['message'] . PHP_EOL;

foreach ($result['runs'] as $run) {
    printf(
        "  %-8s %-16s %-26s %10s  local=%s remote=%s%s\n",
        $run['type'],
        $run['status'],
        $run['file'] ?? '-',
        isset($run['size']) ? number_format((int) $run['size'] / 1024, 1) . ' KB' : '-',
        $run['local']['status'],
        $run['remote']['status'],
        !empty($run['error']) ? '  (' . $run['error'] . ')' : ''
    );
}

switch ($result['status']) {
    case 'FAILED':
        exit(1);
    case 'PARTIAL_SUCCESS':
        exit(2);
    case 'BUSY':
        exit(3);
    default:
        exit(0);
}
