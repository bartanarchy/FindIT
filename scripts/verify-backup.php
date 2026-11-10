<?php

// Checks that every backup file can be opened with the current key and that no
// journal line was removed or changed.   php scripts/verify-backup.php

require_once __DIR__ . '/_bootstrap.php';

requireKey();

$ok = true;

$entries = 0;
$perTable = [];
try {
    foreach (Backup::readJournal() as $entry) {
        $entries++;
        $perTable[$entry['t']] = ($perTable[$entry['t']] ?? 0) + 1;
    }
    out("Journal: OK ($entries entries, chain intact)");
    foreach ($perTable as $table => $n) {
        out("  $table: $n");
    }
} catch (Throwable $e) {
    $ok = false;
    out('Journal: PROBLEM - ' . $e->getMessage());
}

$files = Backup::snapshotFiles();
out('Snapshots: ' . count($files));
foreach ($files as $file) {
    try {
        $snap = Backup::loadSnapshot($file);
        $rows = array_sum(array_map('count', $snap['tables']));
        out(sprintf('  OK   %s  (%d rows, taken %s)', basename($file), $rows, $snap['created']));
    } catch (Throwable $e) {
        $ok = false;
        out('  FAIL ' . basename($file) . ' - ' . $e->getMessage());
    }
}

if (!$files && $entries === 0) {
    $ok = false;
    out('No backup found yet.');
}

exit($ok ? 0 : 1);
