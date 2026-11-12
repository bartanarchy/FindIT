<?php

// Full disaster rehearsal, on the TEST database only (never the real one):
//   1. restore the newest backup into the test database
//   2. make a backup FROM the test database
//   3. delete (drop) the test database
//   4. restore from that backup
//   5. verify tables, row counts, relations, and that encrypted data can still be decrypted
//
// Run this once after setting up, to prove the whole chain works:  php scripts/restore-drill.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/app/backup/bootstrap.php';
define('APP_ENC_KEY', BackupConfig::env('APP_ENC_KEY'));
require_once dirname(__DIR__) . '/app/helpers/crypto.php';

function stepLine(string $status, string $text): void
{
    echo sprintf('  [%-4s] %s', $status, $text) . PHP_EOL;
}

$fail = static function (string $text): never {
    stepLine('FAIL', $text);
    echo PHP_EOL . 'DRILL FAILED' . PHP_EOL;
    exit(1);
};

$testDb = BackupConfig::restoreTestDb();
echo 'Restore drill on database "' . $testDb . '" (the real database is not touched)' . PHP_EOL . PHP_EOL;

// 1
$first = RestoreManager::testRestore(['by' => 'cli:drill']);
if ($first['status'] !== 'SUCCESS') {
    $fail('Step 1: could not restore the newest backup into the test database: ' . $first['error']);
}
$before = $first['counts'];
stepLine('OK', 'Step 1: newest backup restored into the test database (' . array_sum($before) . ' rows)');

// 2
$name = 'findit_drill_' . date('Ymd_His') . '.dump';
$path = BackupConfig::dir('daily') . '/' . $name;
$dump = BackupManager::dumpTo($path . '.part', $testDb, 'backup');
if (!$dump['ok']) {
    $fail('Step 2: backing up the test database failed: ' . $dump['message']);
}
rename($path . '.part', $path);
file_put_contents($path . '.sha256', hash_file('sha256', $path) . '  ' . $name . "\n");
stepLine('OK', 'Step 2: backup made from the test database (' . $name . ')');

// 3
try {
    RestoreManager::dropTestDatabase();
} catch (Throwable $e) {
    @unlink($path);
    @unlink($path . '.sha256');
    $fail('Step 3: could not delete the test database.');
}
$gone = RestoreManager::targetState($testDb);
if ($gone['state'] !== 'MISSING') {
    $fail('Step 3: the test database still exists.');
}
stepLine('OK', 'Step 3: test database deleted (state: ' . $gone['state'] . ')');

// 4
$second = RestoreManager::testRestore(['kind' => 'daily', 'name' => $name, 'source' => 'local', 'by' => 'cli:drill']);
@unlink($path);
@unlink($path . '.sha256');
if ($second['status'] !== 'SUCCESS') {
    $fail('Step 4: restore after deletion failed: ' . $second['error']);
}
stepLine('OK', 'Step 4: database rebuilt from the backup');

// 5
if ($second['counts'] !== $before) {
    $fail('Step 5: row counts differ from before the deletion.');
}
stepLine('OK', 'Step 5a: every table has exactly the same number of rows as before');

if (BackupConfig::env('APP_ENC_KEY') === '') {
    stepLine('SKIP', 'Step 5b: APP_ENC_KEY is empty, nothing to decrypt');
} else {
    try {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', BackupConfig::dbHost(), BackupConfig::dbPort(), $testDb),
            BackupConfig::creds('restore')['user'],
            BackupConfig::creds('restore')['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $row = $pdo->query('SELECT name_user, email_kampus FROM public."User" ORDER BY id_user LIMIT 1')->fetch();
        if ($row) {
            $plain = Crypto::decryptRow($row);
            if ($plain['name_user'] === '[unreadable]' || Crypto::isEncrypted($plain['email_kampus'])) {
                $fail('Step 5b: encrypted data could not be decrypted with the current APP_ENC_KEY.');
            }
            stepLine('OK', 'Step 5b: encrypted data decrypts correctly (first account: ' . $plain['name_user'] . ')');
        } else {
            stepLine('SKIP', 'Step 5b: no user rows to check');
        }
    } catch (Throwable $e) {
        $fail('Step 5b: could not read the restored data.');
    }
}

echo PHP_EOL . 'DRILL PASSED: a deleted database can be rebuilt from a backup.' . PHP_EOL;
exit(0);
