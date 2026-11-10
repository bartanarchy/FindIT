<?php

// Look inside the encrypted backup (needs APP_ENC_KEY in .env). Read-only.
//
//   php scripts/inspect-backup.php                      summary: what is in the snapshot + journal
//   php scripts/inspect-backup.php --journal            list every journal entry (seq, time, table, id)
//   php scripts/inspect-backup.php --table=User         show the decrypted rows of one table
//   php scripts/inspect-backup.php --journal --table=item --id=3    journal entries for one row, decrypted
//
// Tables: category, location, admin, User, item, claim, notification.
// Password hashes are always hidden. The output contains personal data: do not share it.

require_once __DIR__ . '/_bootstrap.php';

requireKey();

$table = null;
$id = null;
foreach ($argv as $arg) {
    if (preg_match('/^--table=(.+)$/', $arg, $m)) {
        $table = $m[1];
    }
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $id = (int) $m[1];
    }
}

if ($table !== null && !isset(Backup::TABLES[$table])) {
    out('Unknown table "' . $table . '". Choose one of: ' . implode(', ', array_keys(Backup::TABLES)));
    exit(1);
}

// Decrypt the encrypted columns and hide secrets.
function readable(array $row): array
{
    $row = Crypto::decryptRow($row);
    foreach (['password_user', 'password_admin'] as $secret) {
        if (isset($row[$secret])) {
            $row[$secret] = '[hidden]';
        }
    }
    foreach (['email_bidx', 'nim_bidx'] as $technical) {
        unset($row[$technical]);
    }
    if (isset($row['photo_url']) && is_array($row['photo_url'])) {
        $row['photo_url'] = '[binary ' . strlen((string) base64_decode($row['photo_url']['__b64'])) . ' bytes]';
    }
    return $row;
}

function show(array $row): void
{
    out(json_encode(readable($row), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$files = Backup::snapshotFiles();
$snapshot = null;
if ($files) {
    $newest = end($files);
    try {
        $snapshot = Backup::loadSnapshot($newest);
    } catch (Throwable $e) {
        out('Cannot open ' . basename($newest) . ': ' . $e->getMessage());
        exit(1);
    }
}

// ---- journal listing -------------------------------------------------------
if (hasFlag('--journal')) {
    $count = 0;
    try {
        foreach (Backup::readJournal() as $entry) {
            if ($table !== null && $entry['t'] !== $table) {
                continue;
            }
            if ($id !== null && (int) $entry['id'] !== $id) {
                continue;
            }
            $count++;
            out(sprintf('#%d  %s  %s  %s  id=%s', $entry['seq'], $entry['ts'], $entry['t'], $entry['a'], $entry['id']));
            if ($table !== null) {
                show($entry['row']);
            }
        }
    } catch (Throwable $e) {
        out('Journal problem: ' . $e->getMessage());
        exit(1);
    }
    out($count . ' journal entr' . ($count === 1 ? 'y' : 'ies') . ' shown.');
    exit(0);
}

// ---- one table from the snapshot ------------------------------------------
if ($table !== null) {
    if (!$snapshot) {
        out('No snapshot yet. Run: php scripts/backup-snapshot.php');
        exit(1);
    }
    $rows = $snapshot['tables'][$table] ?? [];
    out('Snapshot ' . basename($newest) . ' - table ' . $table . ': ' . count($rows) . ' rows');
    foreach ($rows as $row) {
        show($row);
    }
    out('Changes after this snapshot are in the journal: add --journal --table=' . $table);
    exit(0);
}

// ---- summary ---------------------------------------------------------------
if ($snapshot) {
    out('Snapshot: ' . basename($newest) . '  (taken ' . $snapshot['created'] . ', journal position ' . $snapshot['journal_seq'] . ')');
    foreach ($snapshot['tables'] as $name => $rows) {
        out(sprintf('  %-13s %d rows', $name, count($rows)));
    }
} else {
    out('No snapshot yet.');
}

$after = [];
$total = 0;
try {
    foreach (Backup::readJournal() as $entry) {
        $total++;
        if ($entry['seq'] > ($snapshot['journal_seq'] ?? 0)) {
            $after[$entry['t']] = ($after[$entry['t']] ?? 0) + 1;
        }
    }
} catch (Throwable $e) {
    out('Journal problem: ' . $e->getMessage());
    exit(1);
}

out();
out('Journal: ' . $total . ' entries in total, ' . array_sum($after) . ' written after the snapshot');
foreach ($after as $name => $n) {
    out(sprintf('  %-13s %d change(s) after the snapshot', $name, $n));
}
out();
out('More detail: --table=User   or   --journal   or   --journal --table=item --id=3');
