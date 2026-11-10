<?php

// One-time upgrade for an EXISTING database:
//   1. updates the table structure (database/migrations/*.sql)
//   2. encrypts every sensitive value that is still plain text
//   3. fills the blind indexes used for login / duplicate checks
//   4. takes the first full encrypted snapshot (the start of the backup)
// Safe to run again: already-encrypted values are skipped.
//
//   php scripts/migrate-security.php

require_once __DIR__ . '/_bootstrap.php';

requireKey(); // stops with a clear message if the key is missing

out('FindIT security migration');
out('Before continuing: make a normal backup of the database (pgAdmin > Backup)');
out('and store a copy of APP_ENC_KEY offline.');
confirmOrExit('Continue?');

out();
out('1) Updating table structure');
runMigrations($pdo, $root);

out();
out('2) Encrypting existing data');

$pdo->beginTransaction();
try {
    $counts = [];

    foreach (Crypto::FIELD_MAP as $logical => $spec) {
        $cols = $spec['columns'];
        $rows = $pdo->query(
            'SELECT ' . $spec['pk'] . ', ' . implode(', ', $cols) . ' FROM ' . $spec['table']
        )->fetchAll();

        $changed = 0;
        foreach ($rows as $row) {
            $sets = [];
            $params = ['id' => $row[$spec['pk']]];

            foreach ($cols as $col) {
                if ($row[$col] !== null && !Crypto::isEncrypted($row[$col])) {
                    $sets[] = $col . ' = :' . $col;
                    $params[$col] = Crypto::protect($col, $row[$col]);
                }
            }

            // blind indexes for the User table (computed from the plain value)
            if ($logical === 'User') {
                $email = Crypto::reveal('email_kampus', $row['email_kampus']);
                $nim = Crypto::reveal('id_nim', $row['id_nim']);

                $sets[] = 'email_bidx = :email_bidx';
                $params['email_bidx'] = Crypto::blindIndex(normalizeCampusEmail((string) $email));

                if ($nim !== null && $nim !== '') {
                    $sets[] = 'nim_bidx = :nim_bidx';
                    $params['nim_bidx'] = Crypto::blindIndex((string) $nim);
                }
            }

            if ($sets) {
                $pdo->prepare(
                    'UPDATE ' . $spec['table'] . ' SET ' . implode(', ', $sets)
                    . ' WHERE ' . $spec['pk'] . ' = :id'
                )->execute($params);
                $changed++;
            }
        }

        $counts[$logical] = [count($rows), $changed];
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    out('FAILED, nothing was changed: ' . $e->getMessage());
    exit(1);
}

foreach ($counts as $logical => [$total, $changed]) {
    out(sprintf('  %-13s %d rows, %d updated', $logical, $total, $changed));
}

// Accounts that cannot sign in any more because they are not campus emails.
$legacy = [];
foreach ($pdo->query('SELECT id_user, email_kampus FROM public."User"')->fetchAll() as $u) {
    $email = (string) Crypto::reveal('email_kampus', $u['email_kampus']);
    if (!isCampusStudentEmail($email)) {
        $legacy[] = '#' . $u['id_user'] . ' ' . $email;
    }
}

if ($legacy) {
    out();
    out('NOTE: ' . count($legacy) . ' existing account(s) do not use NIM@student.polinema.ac.id');
    out('and will no longer be able to sign in:');
    foreach ($legacy as $line) {
        out('  - ' . $line);
    }
}

out();
out('3) First encrypted snapshot (backup)');
$path = Backup::snapshot($pdo);
out('  saved ' . $path);

out();
out('Done. Next: run  php scripts/verify-backup.php  and schedule  php scripts/backup-snapshot.php  daily.');
