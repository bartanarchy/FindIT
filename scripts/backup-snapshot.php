<?php

// Full encrypted copy of every table. Schedule it (daily is a good start):
//   Windows Task Scheduler / Laragon:  php C:\laragon\www\FindIT\FindIT\scripts\backup-snapshot.php
//   Linux cron:                        0 2 * * *  php /path/to/FindIT/scripts/backup-snapshot.php
// Optional: --keep=60 keeps the 60 newest snapshots (default 30).

require_once __DIR__ . '/_bootstrap.php';

requireKey();

$keep = 30;
foreach ($argv as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    }
}

$path = Backup::snapshot($pdo, $keep);
out('Snapshot saved: ' . $path);
out('Journal position: ' . Backup::lastSeq());
out('Copy the backup folder (' . Backup::dir() . ') to another disk or cloud storage as well.');
