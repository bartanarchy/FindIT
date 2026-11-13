<?php

// Checks that the backup system is set up correctly on this computer, and says
// what to fix if not.   php scripts/backup-check.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/app/backup/bootstrap.php';

$problems = 0;
$warnings = 0;

function check(string $level, string $label, string $detail = ''): void
{
    global $problems, $warnings;
    $tag = ['ok' => ' OK  ', 'warn' => 'WARN ', 'fail' => 'FAIL '][$level];
    if ($level === 'fail') {
        $problems++;
    } elseif ($level === 'warn') {
        $warnings++;
    }
    echo "[$tag] $label" . ($detail !== '' ? " - $detail" : '') . PHP_EOL;
}

echo "FindIT backup check\n\n";

// PHP
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
if (function_exists('proc_open') && !in_array('proc_open', $disabled, true)) {
    check('ok', 'PHP can start programs (proc_open)');
} else {
    check('fail', 'proc_open is disabled in php.ini', 'remove it from disable_functions; pg_dump cannot run otherwise');
}
check(extension_loaded('pdo_pgsql') ? 'ok' : 'fail', 'PHP extension pdo_pgsql');
check(extension_loaded('openssl') ? 'ok' : 'fail', 'PHP extension openssl');

// folders
try {
    $base = BackupConfig::baseDir();
    foreach (['daily', 'logs'] as $sub) {
        BackupConfig::dir($sub);
    }
    $probe = $base . '/.write-test';
    if (@file_put_contents($probe, 'x') === false) {
        throw new RuntimeException('not writable');
    }
    @unlink($probe);
    check('ok', 'Backup folder', $base . ' (outside the website, writable)');
} catch (Throwable $e) {
    check('fail', 'Backup folder', $e->getMessage());
}

// PostgreSQL tools
foreach (['pg_dump', 'pg_restore', 'psql'] as $tool) {
    try {
        $path = BackupConfig::tool($tool);
        check('ok', $tool, $path . ' (version ' . (BackupManager::toolVersion($tool) ?? '?') . ')');
    } catch (RuntimeException $e) {
        check('fail', $tool, $e->getMessage());
    }
}

// database login for backups
$serverMajor = null;
try {
    $creds = BackupConfig::creds('backup');
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', BackupConfig::dbHost(), BackupConfig::dbPort(), BackupConfig::dbName()),
        $creds['user'], $creds['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
    $serverMajor = (int) $pdo->query("SHOW server_version_num")->fetchColumn() / 10000;
    check('ok', 'Database "' . BackupConfig::dbName() . '" reachable', 'as ' . $creds['user'] . ', PostgreSQL ' . (int) $serverMajor);

    $dumpMajor = (int) BackupManager::toolVersion('pg_dump');
    if ($dumpMajor && $dumpMajor < (int) $serverMajor) {
        check('fail', 'pg_dump is older than the server', 'pg_dump ' . $dumpMajor . ' < server ' . (int) $serverMajor . '; set PG_BIN_DIR to the newer bin folder');
    }

    $missing = [];
    foreach (BackupManager::EXPECTED_TABLES as $table) {
        $found = $pdo->prepare("SELECT to_regclass(:t) IS NOT NULL");
        $found->execute(['t' => 'public."' . $table . '"']);
        if (!$found->fetchColumn()) {
            $missing[] = $table;
        }
    }
    check($missing ? 'fail' : 'ok', 'Expected tables', $missing ? 'missing: ' . implode(', ', $missing) : 'all ' . count(BackupManager::EXPECTED_TABLES) . ' present');
} catch (Throwable $e) {
    check('fail', 'Database login for backups', 'cannot connect (' . BackupManager::classifyPgError($e->getMessage())['message'] . ')');
}

// restore login
try {
    $creds = BackupConfig::creds('restore');
    $admin = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', BackupConfig::dbHost(), BackupConfig::dbPort()),
        $creds['user'], $creds['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
    $can = $admin->prepare('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = :u');
    $can->execute(['u' => $creds['user']]);
    if ($can->fetchColumn()) {
        check('ok', 'Restore login can create databases', 'as ' . $creds['user']);
    } else {
        check('warn', 'Restore login cannot create databases', 'set RESTORE_DB_USER / RESTORE_DB_PASS in .env (needed only for restore)');
    }
} catch (Throwable $e) {
    check('warn', 'Restore login', 'cannot connect to the "postgres" maintenance database; restore will not work until fixed');
}

try {
    check('ok', 'Restore test database', BackupConfig::restoreTestDb());
} catch (Throwable $e) {
    check('fail', 'Restore test database', $e->getMessage());
}

// remote
if (!RemoteFactory::isConfigured()) {
    check('warn', 'Remote copy', 'REMOTE_DRIVER is "none": backups will exist on this computer only');
} else {
    try {
        $remote = RemoteFactory::create();
        $test = $remote->test();
        check($test['ok'] ? 'ok' : 'fail', 'Remote copy (' . $remote->describe() . ')', $test['message']);
    } catch (Throwable $e) {
        check('fail', 'Remote copy', $e->getMessage());
    }
}

// key
if (BackupConfig::env('APP_ENC_KEY') === '') {
    check('warn', 'APP_ENC_KEY is empty', 'backups will contain readable data; see scripts/generate-key.php');
} else {
    check('ok', 'APP_ENC_KEY is set', 'keep an OFFLINE copy: backups of encrypted columns are unreadable without it');
}

check('ok', 'Retention', BackupConfig::keepDailyDays() . ' days of daily backups are kept (the newest valid one is never deleted)');

// last backup
try {
    BackupConfig::applyTimezone();
    $last = BackupManager::summary()['last_local'] ?? null;
    if ($last === null) {
        check('warn', 'Last backup', 'none yet. Run: php scripts/backup.php --type=manual');
    } else {
        $ageHours = (time() - (int) strtotime((string) ($last['finished_at'] ?? ''))) / 3600;
        $when = date('d M Y, H:i', (int) strtotime((string) $last['finished_at']));
        check($ageHours > 36 ? 'warn' : 'ok', 'Last backup', $when . ($ageHours > 36 ? ' - older than 36 hours, is the scheduled task running?' : ''));
    }
} catch (Throwable $e) {
    check('warn', 'Last backup', 'could not read the backup history');
}

// automatic backups (Windows Task Scheduler)
if (BackupConfig::isWindows()) {
    $task = ProcessRunner::run(['schtasks', '/Query', '/TN', 'FindIT Backup'], [], 15);
    if ($task['exit'] === 0) {
        check('ok', 'Automatic backup', 'task "FindIT Backup" is installed in Task Scheduler');
    } else {
        check('warn', 'Automatic backup', 'not installed. Run: powershell -ExecutionPolicy Bypass -File scripts\\windows\\install-scheduler.ps1');
    }
}

check('ok', 'Restore from the website', BackupConfig::bool('RESTORE_WEB_ENABLED') ? 'enabled (RESTORE_WEB_ENABLED=true)' : 'off (the terminal restore always works)');

echo PHP_EOL . ($problems ? "$problems problem(s) to fix." : 'No blocking problems.') . ($warnings ? " $warnings warning(s)." : '') . PHP_EOL;
exit($problems ? 1 : 0);
