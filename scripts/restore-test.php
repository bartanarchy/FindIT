<?php

// Periodic restore test: restores a backup into the throw-away database
// (RESTORE_TEST_DB, default findit_restore_test) and checks tables, data and relations.
// It can never touch the real database. Schedule it weekly.
//
//   php scripts/restore-test.php                           newest local backup
//   php scripts/restore-test.php --file=daily/findit_....dump

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/app/backup/bootstrap.php';

$opts = ['by' => 'cli:restore-test'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--file=(daily|weekly)\/(findit_[A-Za-z0-9_.-]+\.dump)$/', $arg, $m)) {
        $opts += ['kind' => $m[1], 'name' => $m[2], 'source' => 'local'];
    } else {
        fwrite(STDERR, "Unknown or invalid option: $arg\n");
        exit(1);
    }
}

try {
    $rec = RestoreManager::testRestore($opts);
} catch (Throwable $e) {
    fwrite(STDERR, 'The restore test could not start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($rec['steps'] as $step) {
    echo sprintf('  [%-7s] %s%s', $step['status'], $step['name'], $step['detail'] !== '' ? ' - ' . $step['detail'] : '') . PHP_EOL;
}
foreach ($rec['warnings'] as $warning) {
    echo '  [WARNING] ' . $warning . PHP_EOL;
}

if ($rec['status'] === 'SUCCESS') {
    echo PHP_EOL . 'RESTORE TEST PASSED (' . $rec['file'] . ')' . PHP_EOL;
    foreach ($rec['counts'] ?? [] as $table => $n) {
        echo sprintf('  %-13s %d rows', $table, $n) . PHP_EOL;
    }
    exit(0);
}

echo PHP_EOL . 'RESTORE TEST FAILED: ' . $rec['error'] . PHP_EOL;
exit(1);
