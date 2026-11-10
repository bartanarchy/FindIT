<?php

// Rebuilds the database from the encrypted backup:
//   newest snapshot  +  every journal entry written after that snapshot.
//
//   php scripts/restore.php --dry-run     show what would be restored
//   php scripts/restore.php               restore (asks for confirmation)
//   php scripts/restore.php --yes         restore without asking
//
// Works even if the whole database was dropped: create an empty "FindIT" database
// (pgAdmin > Create > Database) and run this script; it recreates the tables first.
// Rows already in the database are updated to the backed-up version; rows that are
// not in the backup are left alone.

require_once __DIR__ . '/_bootstrap.php';

requireKey();
$dry = hasFlag('--dry-run');

// 1. Load the backup into memory (this also verifies it).
$snapshot = null;
$files = Backup::snapshotFiles();
if ($files) {
    $newest = end($files);
    try {
        $snapshot = Backup::loadSnapshot($newest);
    } catch (Throwable $e) {
        out('Cannot open ' . basename($newest) . ': ' . $e->getMessage());
        out('Check that APP_ENC_KEY in .env is the same key the backup was made with.');
        exit(1);
    }
    out('Snapshot: ' . basename($newest) . ' (' . $snapshot['created'] . ')');
} else {
    // The journal only holds rows the app wrote (accounts, reports, claims). Categories and
    // the admin account come from the snapshot, so restoring without one could attach
    // items to the wrong category.
    out('No snapshot found. A restore needs at least one snapshot as its base.');
    out('Run  php scripts/backup-snapshot.php  regularly, and keep copies of the backup folder.');
    exit(1);
}

$fromSeq = $snapshot['journal_seq'];
$replay = [];
try {
    foreach (Backup::readJournal() as $entry) {
        if ($entry['seq'] > $fromSeq) {
            $replay[] = $entry;
        }
    }
} catch (Throwable $e) {
    out('The journal cannot be used: ' . $e->getMessage());
    out('Run  php scripts/verify-backup.php  for details.');
    exit(1);
}
out('Journal entries after the snapshot: ' . count($replay));

foreach ($snapshot['tables'] as $table => $rows) {
    out(sprintf('  %-13s %d rows in snapshot', $table, count($rows)));
}

if ($dry) {
    out();
    out('Dry run - nothing was changed.');
    exit(0);
}

confirmOrExit("\nThis writes the backed-up data into database '" . ($env['DB_NAME'] ?? 'FindIT') . "'.");

// 2. Recreate the tables if the database was wiped.
$exists = $pdo->query("SELECT to_regclass('public.\"User\"') IS NOT NULL")->fetchColumn();
if (!$exists) {
    out('Tables are missing - recreating them from database/schema.sql');
    $schema = (string) file_get_contents($root . '/database/schema.sql');
    // schema.sql was dumped from PostgreSQL 18; older servers don't know this setting.
    if ((int) $pdo->query('SHOW server_version_num')->fetchColumn() < 170000) {
        $schema = preg_replace('/^SET transaction_timeout.*$/m', '', $schema);
    }
    $pdo->exec($schema);
    $pdo->exec('SET search_path TO public');
}
runMigrations($pdo, $root);

// 3. Write everything back in one transaction.
$pdo->beginTransaction();
try {
    $restored = 0;

    foreach (array_keys(Backup::TABLES) as $table) {
        foreach ($snapshot['tables'][$table] ?? [] as $row) {
            Backup::upsertRow($pdo, $table, $row);
            $restored++;
        }
    }

    foreach ($replay as $entry) {
        Backup::upsertRow($pdo, $entry['t'], $entry['row']);
        $restored++;
    }

    Backup::fixSequences($pdo);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    out('FAILED, the database was not changed: ' . $e->getMessage());
    exit(1);
}

out();
out("Restored $restored row writes. The application can be used again.");
