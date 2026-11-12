<?php

/**
 * Creates, validates, copies and cleans up database backups.
 *
 * One backup = a PostgreSQL custom-format dump (.dump, restorable with pg_restore)
 * plus a .sha256 checksum file, plus a JSON run record in logs/runs/.
 *
 * Status of a run:
 *   RUNNING          in progress
 *   SUCCESS          local copy valid AND remote copy verified
 *   PARTIAL_SUCCESS  local copy valid, but the remote copy is missing/failed/not configured
 *   FAILED           no valid local copy was made
 */
final class BackupManager
{
    /** Tables that must be inside every backup. */
    public const EXPECTED_TABLES = ['User', 'admin', 'category', 'claim', 'item', 'location', 'notification'];

    private const MIN_DUMP_BYTES = 1024;

    // ======================================================================
    //  Running a backup
    // ======================================================================

    /**
     * @param string $type auto (daily, plus weekly when due) | daily | weekly | manual
     * @param array  $opts force, trigger (task|cli|dashboard), by, retries, retry_delay
     * @return array{status:string,message:string,runs:array}
     */
    public static function run(string $type = 'auto', array $opts = []): array
    {
        BackupConfig::applyTimezone();

        if (!in_array($type, ['auto', 'daily', 'weekly', 'manual'], true)) {
            throw new InvalidArgumentException('Unknown backup type.');
        }

        $force = !empty($opts['force']) || $type === 'manual';
        $context = [
            'trigger' => (string) ($opts['trigger'] ?? 'cli'),
            'by' => isset($opts['by']) ? (string) $opts['by'] : null,
            'retries' => max(1, (int) ($opts['retries'] ?? 1)),
            'retry_delay' => max(1, (int) ($opts['retry_delay'] ?? 30)),
        ];

        $lock = OperationLock::acquire('backup');
        if ($lock === null) {
            return ['status' => 'BUSY', 'message' => 'Another backup or restore is already running.', 'runs' => []];
        }

        try {
            self::reapStale();

            // A laptop that was just switched on must not trigger endless repeat backups.
            if (!$force && in_array($type, ['auto', 'daily'], true)) {
                $recent = self::recentGoodBackup(BackupConfig::minIntervalHours());
                if ($recent !== null) {
                    BackupStore::log('backup', 'info', 'skipped_recent_backup', ['last_run' => $recent['id']]);
                    $retried = self::retryPendingRemote();
                    return [
                        'status' => 'SKIPPED',
                        'message' => 'A backup was already made at ' . $recent['finished_at']
                            . '. Use --force to make another one.',
                        'runs' => $retried,
                    ];
                }
            }

            $runs = [];

            if ($type === 'weekly') {
                $weeklyFile = BackupConfig::dir('weekly') . '/' . self::weeklyName();
                if (is_file($weeklyFile) && !$force) {
                    return ['status' => 'SKIPPED', 'message' => 'This week already has a weekly backup.', 'runs' => []];
                }
                $runs[] = self::makeBackup('weekly', $context);
            } else {
                $kind = $type === 'manual' ? 'manual' : 'daily';
                $primary = self::makeBackup($kind, $context);
                $runs[] = $primary;

                $weeklyDue = $type === 'auto'
                    && $primary['local']['status'] === 'SUCCESS'
                    && !is_file(BackupConfig::dir('weekly') . '/' . self::weeklyName());

                if ($weeklyDue) {
                    $runs[] = self::makeWeeklyCopy($primary, $context);
                }
            }

            // Earlier copies that never reached the remote get another chance now.
            foreach (self::retryPendingRemote(array_column($runs, 'id')) as $retried) {
                $runs[] = $retried;
            }

            $anyLocalOk = false;
            foreach ($runs as $r) {
                if (($r['local']['status'] ?? '') === 'SUCCESS') {
                    $anyLocalOk = true;
                }
            }

            // Old backups are only removed after a NEW valid backup exists.
            if ($anyLocalOk) {
                self::applyRetention();
            }

            return [
                'status' => $runs[0]['status'],
                'message' => self::statusMessage($runs[0]),
                'runs' => $runs,
            ];
        } finally {
            $lock->release();
        }
    }

    /** Only retry the copies that are missing on the remote (used by --retry-remote). */
    public static function retryRemoteOnly(): array
    {
        BackupConfig::applyTimezone();

        $lock = OperationLock::acquire('backup');
        if ($lock === null) {
            return ['status' => 'BUSY', 'message' => 'Another backup or restore is already running.', 'runs' => []];
        }

        try {
            self::reapStale();
            $runs = self::retryPendingRemote();
            return [
                'status' => 'DONE',
                'message' => count($runs) . ' remote copy attempt(s) made.',
                'runs' => $runs,
            ];
        } finally {
            $lock->release();
        }
    }

    private static function weeklyName(): string
    {
        return 'findit_' . date('o-\WW') . '.dump';
    }

    private static function newRecord(string $type, array $context): array
    {
        return [
            'id' => BackupStore::newRunId(),
            'type' => $type,
            'trigger' => $context['trigger'],
            'requested_by' => $context['by'],
            'started_at' => BackupStore::now(),
            'finished_at' => null,
            'database' => BackupConfig::dbName(),
            'status' => 'RUNNING',
            'file' => null,
            'size' => null,
            'sha256' => null,
            'pg_dump_version' => null,
            'validation' => ['status' => 'NOT_RUN', 'tables' => 0, 'message' => ''],
            'local' => ['status' => 'PENDING', 'error' => null],
            'remote' => [
                'driver' => RemoteFactory::driver(),
                'status' => 'PENDING',
                'attempts' => 0,
                'last_attempt' => null,
                'verified_at' => null,
                'error' => null,
            ],
            'error' => null,
            'restores' => [],
        ];
    }

    /** Make one new dump (daily, manual or weekly). */
    private static function makeBackup(string $kind, array $context): array
    {
        $run = self::newRecord($kind, $context);
        BackupStore::saveRun($run);
        BackupStore::log('backup', 'info', 'backup_started', ['run' => $run['id'], 'type' => $kind, 'trigger' => $context['trigger']]);

        $partFile = null;

        try {
            $dirKind = $kind === 'weekly' ? 'weekly' : 'daily';
            $name = $kind === 'weekly' ? self::weeklyName() : 'findit_' . date('Y-m-d_His') . '.dump';
            $dir = BackupConfig::dir($dirKind);
            $final = $dir . '/' . $name;
            $partFile = $dir . '/.' . $name . '.part';

            $run['pg_dump_version'] = self::toolVersion('pg_dump');

            // 1. dump (with a few retries when PostgreSQL is simply not ready yet)
            $result = null;
            for ($attempt = 1; $attempt <= $context['retries']; $attempt++) {
                $result = self::dumpTo($partFile, null, 'backup');
                if ($result['ok'] || empty($result['retryable']) || $attempt === $context['retries']) {
                    break;
                }
                BackupStore::log('backup', 'warning', 'database_not_ready_retrying', ['run' => $run['id'], 'attempt' => $attempt]);
                sleep($context['retry_delay']);
            }

            if (!$result['ok']) {
                throw new BackupFailure($result['message'], $result['code']);
            }

            // 2. validate BEFORE the file is allowed to look like a backup
            $check = self::validateDump($partFile);
            $run['validation'] = [
                'status' => $check['ok'] ? 'PASSED' : 'FAILED',
                'tables' => count($check['tables']),
                'message' => $check['message'],
            ];
            if (!$check['ok']) {
                throw new BackupFailure('The backup file did not pass validation: ' . $check['message'], 'VALIDATION');
            }

            // 3. checksum, move into place, checksum file
            $sha = (string) hash_file('sha256', $partFile);
            $size = (int) filesize($partFile);

            if (!@rename($partFile, $final)) {
                throw new BackupFailure('The backup file could not be moved into place.', 'FILESYSTEM');
            }
            @chmod($final, 0600);
            file_put_contents($final . '.sha256', $sha . '  ' . $name . "\n");

            $run['file'] = $dirKind . '/' . $name;
            $run['size'] = $size;
            $run['sha256'] = $sha;
            $run['table_counts'] = self::tableCounts();
            $run['local'] = ['status' => 'SUCCESS', 'error' => null];
            $partFile = null;
        } catch (BackupFailure $e) {
            $run['local'] = ['status' => 'FAILED', 'error' => $e->getMessage()];
            $run['error'] = $e->getMessage();
            $run['error_code'] = $e->codeName;
        } catch (Throwable $e) {
            BackupStore::log('backup', 'error', 'unexpected_error', ['run' => $run['id'], 'detail' => ProcessRunner::redact($e->getMessage(), self::secrets())]);
            $run['local'] = ['status' => 'FAILED', 'error' => 'An unexpected error stopped the backup. See the log.'];
            $run['error'] = $run['local']['error'];
            $run['error_code'] = 'UNEXPECTED';
        }

        if ($partFile !== null) {
            @unlink($partFile);
        }

        if ($run['local']['status'] === 'SUCCESS') {
            self::sendRemote($run);
        } else {
            $run['remote']['status'] = 'SKIPPED';
        }

        return self::finish($run);
    }

    /** The weekly backup is a verified copy of today's daily dump. */
    private static function makeWeeklyCopy(array $daily, array $context): array
    {
        $run = self::newRecord('weekly', $context);
        $run['pg_dump_version'] = $daily['pg_dump_version'];
        BackupStore::saveRun($run);

        try {
            $name = self::weeklyName();
            $dir = BackupConfig::dir('weekly');
            $source = BackupConfig::dir('daily') . '/' . basename((string) $daily['file']);
            $part = $dir . '/.' . $name . '.part';

            if (!@copy($source, $part)) {
                throw new BackupFailure('The weekly copy could not be created.', 'FILESYSTEM');
            }
            if (hash_file('sha256', $part) !== $daily['sha256']) {
                @unlink($part);
                throw new BackupFailure('The weekly copy does not match the daily backup.', 'CHECKSUM');
            }

            rename($part, $dir . '/' . $name);
            @chmod($dir . '/' . $name, 0600);
            file_put_contents($dir . '/' . $name . '.sha256', $daily['sha256'] . '  ' . $name . "\n");

            $run['file'] = 'weekly/' . $name;
            $run['size'] = $daily['size'];
            $run['sha256'] = $daily['sha256'];
            $run['table_counts'] = $daily['table_counts'] ?? null;
            $run['validation'] = $daily['validation'];
            $run['local'] = ['status' => 'SUCCESS', 'error' => null];
        } catch (BackupFailure $e) {
            $run['local'] = ['status' => 'FAILED', 'error' => $e->getMessage()];
            $run['error'] = $e->getMessage();
        }

        if ($run['local']['status'] === 'SUCCESS') {
            self::sendRemote($run);
        } else {
            $run['remote']['status'] = 'SKIPPED';
        }

        return self::finish($run);
    }

    private static function finish(array $run): array
    {
        $run['finished_at'] = BackupStore::now();
        $run['status'] = self::computeStatus($run);
        BackupStore::saveRun($run);

        BackupStore::log('backup', $run['status'] === 'FAILED' ? 'error' : 'info', 'backup_finished', [
            'run' => $run['id'],
            'type' => $run['type'],
            'status' => $run['status'],
            'file' => $run['file'],
            'size' => $run['size'],
            'local' => $run['local']['status'],
            'remote' => $run['remote']['status'],
            'error' => $run['error'],
        ]);

        return $run;
    }

    public static function computeStatus(array $run): string
    {
        if (($run['local']['status'] ?? '') !== 'SUCCESS') {
            return ($run['status'] ?? '') === 'RUNNING' && ($run['local']['status'] ?? '') === 'PENDING'
                ? 'RUNNING'
                : 'FAILED';
        }

        return ($run['remote']['status'] ?? '') === 'SUCCESS' ? 'SUCCESS' : 'PARTIAL_SUCCESS';
    }

    private static function statusMessage(array $run): string
    {
        switch ($run['status']) {
            case 'SUCCESS':
                return 'Backup saved locally and verified on the remote copy.';
            case 'PARTIAL_SUCCESS':
                return $run['remote']['status'] === 'NOT_CONFIGURED'
                    ? 'Backup saved locally. No remote copy is configured, so there is only ONE copy.'
                    : 'Backup saved locally, but the remote copy failed: ' . ($run['remote']['error'] ?? 'unknown error');
            default:
                return 'Backup failed: ' . ($run['error'] ?? 'unknown error');
        }
    }

    // ======================================================================
    //  pg_dump and validation
    // ======================================================================

    /**
     * Dump a database into $targetFile (custom format).
     *
     * @return array{ok:bool,code:string,message:string,retryable:bool}
     */
    public static function dumpTo(string $targetFile, ?string $database = null, string $role = 'backup'): array
    {
        $database = $database ?? BackupConfig::dbName();
        self::assertPlainName($database);
        $creds = BackupConfig::creds($role);

        try {
            $pgDump = BackupConfig::tool('pg_dump');
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => 'NO_PG_DUMP', 'message' => $e->getMessage(), 'retryable' => false];
        }

        $command = [
            $pgDump,
            '--format=custom',
            '--compress=6',
            '--no-owner',
            '--no-password',
            '--host=' . BackupConfig::dbHost(),
            '--port=' . BackupConfig::dbPort(),
            '--username=' . $creds['user'],
            '--file=' . $targetFile,
            '--dbname=' . $database,
        ];

        $result = ProcessRunner::run($command, ['PGPASSWORD' => $creds['pass']], 900);

        if ($result['exit'] === 0 && is_file($targetFile)) {
            return ['ok' => true, 'code' => 'OK', 'message' => '', 'retryable' => false];
        }

        @unlink($targetFile);
        $failure = self::classifyPgError($result['stderr'], $result['timed_out']);

        BackupStore::log('backup', 'error', 'pg_dump_failed', [
            'code' => $failure['code'],
            'exit' => $result['exit'],
            'detail' => ProcessRunner::redact($result['stderr'], self::secrets()),
        ]);

        return ['ok' => false] + $failure;
    }

    /** Turn raw PostgreSQL tool output into a safe message (details only go to the log). */
    public static function classifyPgError(string $stderr, bool $timedOut = false): array
    {
        if ($timedOut) {
            return ['code' => 'TIMEOUT', 'message' => 'The database tool took too long and was stopped.', 'retryable' => false];
        }
        if (preg_match('/could not connect|connection refused|timed out|server closed the connection|starting up|not accepting connections|could not translate host/i', $stderr)) {
            return ['code' => 'DB_UNREACHABLE', 'message' => 'PostgreSQL cannot be reached. Check that the PostgreSQL service is running.', 'retryable' => true];
        }
        if (preg_match('/password authentication failed|no password supplied|authentication failed|role ".*" does not exist/i', $stderr)) {
            return ['code' => 'DB_AUTH', 'message' => 'The database login was rejected. Check the backup account in .env.', 'retryable' => false];
        }
        if (preg_match('/database ".*" does not exist/i', $stderr)) {
            return ['code' => 'DB_MISSING', 'message' => 'The database does not exist. Use the recovery procedure (scripts/restore.php).', 'retryable' => false];
        }
        if (preg_match('/server version mismatch|unsupported version/i', $stderr)) {
            return ['code' => 'PG_VERSION', 'message' => 'pg_dump is older than the PostgreSQL server. Set PG_BIN_DIR to the matching version.', 'retryable' => false];
        }
        if (preg_match('/permission denied/i', $stderr)) {
            return ['code' => 'DB_PERMISSION', 'message' => 'The database account does not have enough permission for this job.', 'retryable' => false];
        }
        return ['code' => 'PG_ERROR', 'message' => 'The database tool reported an error. See logs/backup.log.', 'retryable' => false];
    }

    /**
     * Check that a dump can really be used: size, format header, and that
     * pg_restore can read its contents and finds every expected table + its data.
     *
     * @return array{ok:bool,message:string,tables:string[],missing:string[]}
     */
    public static function validateDump(string $file): array
    {
        $fail = static fn(string $m, array $t = [], array $missing = []) => ['ok' => false, 'message' => $m, 'tables' => $t, 'missing' => $missing];

        if (!is_file($file)) {
            return $fail('The file does not exist.');
        }
        if (filesize($file) < self::MIN_DUMP_BYTES) {
            return $fail('The file is empty or too small.');
        }

        $handle = fopen($file, 'rb');
        $header = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($header !== 'PGDMP') {
            return $fail('The file is not a PostgreSQL backup.');
        }

        try {
            $pgRestore = BackupConfig::tool('pg_restore');
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        }

        $list = ProcessRunner::run([$pgRestore, '--list', $file], [], 180);
        if ($list['exit'] !== 0) {
            return $fail('pg_restore cannot read this file (it may be damaged).');
        }

        $tables = [];
        $withData = [];
        foreach (preg_split('/\R/', $list['stdout']) ?: [] as $line) {
            if (preg_match('/;\s+\d+\s+\d+\s+TABLE DATA\s+public\s+"?([^"\s]+)"?(\s|$)/', $line, $m)) {
                $withData[$m[1]] = true;
            } elseif (preg_match('/;\s+\d+\s+\d+\s+TABLE\s+public\s+"?([^"\s]+)"?(\s|$)/', $line, $m)) {
                $tables[$m[1]] = true;
            }
        }

        $missing = [];
        foreach (self::EXPECTED_TABLES as $expected) {
            if (!isset($tables[$expected]) || !isset($withData[$expected])) {
                $missing[] = $expected;
            }
        }

        if ($missing) {
            return $fail('Missing tables: ' . implode(', ', $missing), array_keys($tables), $missing);
        }

        return ['ok' => true, 'message' => 'All ' . count(self::EXPECTED_TABLES) . ' tables found.', 'tables' => array_keys($tables), 'missing' => []];
    }

    /**
     * Rows per table right now (informational: used later to compare a restore with the
     * backup). Null when it cannot be read; a backup never fails because of this.
     *
     * @return array<string,int>|null
     */
    public static function tableCounts(?string $database = null, string $role = 'backup'): ?array
    {
        try {
            $database = $database ?? BackupConfig::dbName();
            self::assertPlainName($database);
            $creds = BackupConfig::creds($role);
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', BackupConfig::dbHost(), BackupConfig::dbPort(), $database),
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );

            $counts = [];
            foreach (self::EXPECTED_TABLES as $table) {
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
            }
            return $counts;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function toolVersion(string $tool): ?string
    {
        try {
            $r = ProcessRunner::run([BackupConfig::tool($tool), '--version'], [], 20);
            return $r['exit'] === 0 && preg_match('/(\d+(\.\d+)*)/', $r['stdout'], $m) ? $m[1] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Database and tool names that end up on a command line must be plain. */
    public static function assertPlainName(string $name): void
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,63}\z/', $name)) {
            throw new RuntimeException('The database name contains unsupported characters.');
        }
    }

    /** Values that must never appear in a log line. */
    public static function secrets(): array
    {
        return [
            BackupConfig::creds('backup')['pass'],
            BackupConfig::creds('restore')['pass'],
            BackupConfig::env('DB_PASS'),
            BackupConfig::env('APP_ENC_KEY'),
        ];
    }

    // ======================================================================
    //  Remote copy
    // ======================================================================

    /** Copy a finished local backup to the remote storage and verify it there. */
    private static function sendRemote(array &$run): void
    {
        $remote = &$run['remote'];
        $remote['driver'] = RemoteFactory::driver();

        if (!RemoteFactory::isConfigured()) {
            $remote['status'] = 'NOT_CONFIGURED';
            $remote['error'] = null;
            return;
        }

        $remote['attempts'] = (int) $remote['attempts'] + 1;
        $remote['last_attempt'] = BackupStore::now();

        try {
            $driver = RemoteFactory::create();
            [$kind, $name] = explode('/', (string) $run['file'], 2);
            $path = BackupConfig::dir($kind) . '/' . $name;

            if (!is_file($path)) {
                throw new RuntimeException('The local file is gone, nothing to send.');
            }

            $driver->upload($path, $name, $kind);
            $driver->upload($path . '.sha256', $name . '.sha256', $kind);

            // "Uploaded" is not enough: ask the remote what it actually stored.
            $stat = $driver->stat($name, $kind);
            if ($stat === null) {
                throw new RuntimeException('The remote copy cannot be found after upload.');
            }
            if ($stat['size'] !== (int) $run['size'] || !hash_equals((string) $run['sha256'], (string) $stat['sha256'])) {
                throw new RuntimeException('The remote copy does not match the local file (size or checksum).');
            }

            $remote['status'] = 'SUCCESS';
            $remote['verified_at'] = BackupStore::now();
            $remote['error'] = null;
            $remote['location'] = $driver->describe();
        } catch (Throwable $e) {
            $remote['status'] = 'FAILED';
            $remote['error'] = $e->getMessage();
            BackupStore::log('backup', 'warning', 'remote_copy_failed', [
                'run' => $run['id'],
                'detail' => ProcessRunner::redact($e->getMessage(), self::secrets()),
            ]);
        }
    }

    /**
     * Retry the remote copy of earlier backups that still have no verified remote copy.
     *
     * @param string[] $skipIds runs that were just handled in this process
     * @return array[] updated run records
     */
    private static function retryPendingRemote(array $skipIds = []): array
    {
        if (!RemoteFactory::isConfigured()) {
            return [];
        }

        $updated = [];

        foreach (BackupStore::listRuns(60) as $run) {
            if (count($updated) >= 10) {
                break;
            }

            $pending = ($run['local']['status'] ?? '') === 'SUCCESS'
                && empty($run['file_deleted_at'])
                && in_array($run['remote']['status'] ?? '', ['FAILED', 'PENDING', 'NOT_CONFIGURED'], true)
                && !in_array($run['id'], $skipIds, true)
                && !empty($run['file']);

            if (!$pending) {
                continue;
            }

            [$kind, $name] = explode('/', (string) $run['file'], 2);
            if (!is_file(BackupConfig::dir($kind) . '/' . $name)) {
                continue;
            }

            BackupStore::log('backup', 'info', 'remote_retry', ['run' => $run['id']]);
            self::sendRemote($run);
            $run['status'] = self::computeStatus($run);
            BackupStore::saveRun($run);
            $updated[] = $run;
        }

        return $updated;
    }

    // ======================================================================
    //  Retention and housekeeping
    // ======================================================================

    /** @return string[] names of deleted files */
    private static function applyRetention(): array
    {
        $deleted = [];
        $limits = [
            'daily' => BackupConfig::keepDailyDays() * 86400,
            'weekly' => BackupConfig::keepWeeklyWeeks() * 7 * 86400,
        ];

        foreach ($limits as $kind => $maxAge) {
            $files = glob(BackupConfig::dir($kind) . '/findit_*.dump') ?: [];
            usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

            // The newest file in each folder is never deleted, however old it is.
            foreach (array_slice($files, 1) as $file) {
                if (time() - filemtime($file) <= $maxAge) {
                    continue;
                }

                $name = basename($file);
                @unlink($file . '.sha256');
                if (@unlink($file)) {
                    $deleted[] = $kind . '/' . $name;
                    self::markFileDeleted($kind . '/' . $name);
                    BackupStore::log('backup', 'info', 'retention_deleted', ['scope' => 'local', 'file' => $kind . '/' . $name]);
                }
            }
        }

        // Leftovers of interrupted work.
        foreach (glob(BackupConfig::dir('tmp') . '/*') ?: [] as $tmp) {
            if (is_file($tmp) && time() - filemtime($tmp) > 86400) {
                @unlink($tmp);
            }
        }

        if (RemoteFactory::isConfigured() && BackupConfig::bool('REMOTE_RETENTION_BY_APP')) {
            self::applyRemoteRetention($limits);
        }

        return $deleted;
    }

    /** Optional: let this app delete old remote copies. Better done on the server itself. */
    private static function applyRemoteRetention(array $limits): void
    {
        try {
            $driver = RemoteFactory::create();

            foreach ($limits as $kind => $maxAge) {
                $files = $driver->listFiles($kind);
                usort($files, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

                foreach (array_slice($files, 1) as $file) {
                    if (time() - $file['mtime'] > $maxAge) {
                        $driver->delete($file['name'], $kind);
                        BackupStore::log('backup', 'info', 'retention_deleted', ['scope' => 'remote', 'file' => $kind . '/' . $file['name']]);
                    }
                }
            }
        } catch (Throwable $e) {
            BackupStore::log('backup', 'warning', 'remote_retention_failed', ['detail' => ProcessRunner::redact($e->getMessage(), self::secrets())]);
        }
    }

    private static function markFileDeleted(string $relative): void
    {
        foreach (BackupStore::listRuns(500) as $run) {
            if (($run['file'] ?? null) === $relative && empty($run['file_deleted_at'])) {
                $run['file_deleted_at'] = BackupStore::now();
                BackupStore::saveRun($run);
            }
        }
    }

    /** A run left in RUNNING state by a crashed process is closed as FAILED. */
    private static function reapStale(): void
    {
        foreach (BackupStore::listRuns(50) as $run) {
            if (($run['status'] ?? '') === 'RUNNING') {
                $run['status'] = 'FAILED';
                $run['finished_at'] = BackupStore::now();
                $run['error'] = 'The process stopped before it finished.';
                if (($run['local']['status'] ?? '') === 'PENDING') {
                    $run['local'] = ['status' => 'FAILED', 'error' => $run['error']];
                }
                BackupStore::saveRun($run);
            }
        }
    }

    private static function recentGoodBackup(int $hours): ?array
    {
        if ($hours <= 0) {
            return null;
        }

        foreach (BackupStore::listRuns(30) as $run) {
            if (in_array($run['type'] ?? '', ['daily', 'manual'], true)
                && ($run['local']['status'] ?? '') === 'SUCCESS'
                && empty($run['file_deleted_at'])
                && !empty($run['finished_at'])
                && strtotime($run['finished_at']) > time() - $hours * 3600
            ) {
                return $run;
            }
        }
        return null;
    }

    // ======================================================================
    //  Reading state (dashboard, restore lists)
    // ======================================================================

    /** History for the dashboard; a RUNNING record without a live process shows as FAILED. */
    public static function history(int $limit = 100): array
    {
        BackupConfig::applyTimezone();
        $running = OperationLock::running();
        $runs = BackupStore::listRuns($limit);

        foreach ($runs as &$run) {
            if (($run['status'] ?? '') === 'RUNNING' && (!$running || ($running['run_id'] ?? $run['id']) !== $run['id'])) {
                // Only the newest RUNNING record can belong to a live process.
                if (!$running) {
                    $run['status'] = 'FAILED';
                    $run['error'] = $run['error'] ?? 'The process stopped before it finished.';
                }
            }
        }
        unset($run);

        return $runs;
    }

    /** Backup files that exist on this computer, newest first. */
    public static function listLocalBackups(): array
    {
        $byFile = [];
        foreach (BackupStore::listRuns(500) as $run) {
            if (!empty($run['file']) && empty($run['file_deleted_at']) && !isset($byFile[$run['file']])) {
                $byFile[$run['file']] = $run;
            }
        }

        $items = [];
        foreach (['daily', 'weekly'] as $kind) {
            foreach (glob(BackupConfig::dir($kind) . '/findit_*.dump') ?: [] as $path) {
                $name = basename($path);
                if (!RemoteFactory::safeName($name)) {
                    continue;
                }

                $run = $byFile[$kind . '/' . $name] ?? null;
                $items[] = [
                    'kind' => $kind,
                    'name' => $name,
                    'size' => (int) filesize($path),
                    'mtime' => (int) filemtime($path),
                    'has_checksum' => is_file($path . '.sha256'),
                    'type' => $run['type'] ?? ($kind === 'weekly' ? 'weekly' : 'unknown'),
                    'validation' => $run['validation']['status'] ?? 'NOT_RUN',
                    'remote' => $run['remote']['status'] ?? 'UNKNOWN',
                    'run_id' => $run['id'] ?? null,
                ];
            }
        }

        usort($items, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /**
     * Safe full path of a local backup file. Rejects anything that is not exactly
     * one of our own files inside daily/ or weekly/ (path traversal, symlinks, odd names).
     */
    public static function resolveLocal(string $kind, string $name): string
    {
        RemoteFactory::assertValid($name, $kind);

        if (substr($name, -7) === '.sha256') {
            throw new InvalidArgumentException('Invalid backup file name.');
        }

        $base = realpath(BackupConfig::dir($kind));
        $path = $base . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);

        if ($base === false || $real === false || is_link($path) || !is_file($real) || dirname($real) !== $base) {
            throw new RuntimeException('The backup file was not found.');
        }

        return $real;
    }

    /** Everything the Backup Management page needs, read from files only. */
    public static function summary(): array
    {
        BackupConfig::applyTimezone();

        $runs = self::history(200);
        $real = array_values(array_filter($runs, static fn($r) => ($r['type'] ?? '') !== 'pre-restore'));

        $lastRun = $real[0] ?? null;
        $lastLocal = null;
        $lastFull = null;
        $lastTest = null;

        foreach ($real as $run) {
            if ($lastLocal === null && ($run['local']['status'] ?? '') === 'SUCCESS') {
                $lastLocal = $run;
            }
            if ($lastFull === null && ($run['status'] ?? '') === 'SUCCESS') {
                $lastFull = $run;
            }
        }

        $testFile = BackupConfig::dir('logs') . '/restore-test.json';
        if (is_file($testFile)) {
            $lastTest = json_decode((string) file_get_contents($testFile), true) ?: null;
        }

        $files = self::listLocalBackups();
        $totalSize = array_sum(array_column($files, 'size'));

        $remoteInfo = ['driver' => RemoteFactory::driver(), 'configured' => RemoteFactory::isConfigured(), 'description' => null, 'error' => null];
        if ($remoteInfo['configured']) {
            try {
                $remoteInfo['description'] = RemoteFactory::create()->describe();
            } catch (Throwable $e) {
                $remoteInfo['error'] = $e->getMessage();
            }
        }

        $pendingRemote = 0;
        foreach ($real as $run) {
            if (($run['local']['status'] ?? '') === 'SUCCESS' && empty($run['file_deleted_at'])
                && in_array($run['remote']['status'] ?? '', ['FAILED', 'PENDING', 'NOT_CONFIGURED'], true)) {
                $pendingRemote++;
            }
        }

        $warnings = [];
        if ($lastLocal === null) {
            $warnings[] = 'No backup has been made yet. Press "Backup Now".';
        } elseif (strtotime((string) $lastLocal['finished_at']) < time() - 36 * 3600) {
            $warnings[] = 'The last good backup is older than 36 hours.';
        }
        if (!$remoteInfo['configured']) {
            $warnings[] = 'No remote copy is configured: every backup exists on this computer only.';
        } elseif ($remoteInfo['error'] !== null) {
            $warnings[] = 'The remote settings are invalid: ' . $remoteInfo['error'];
        } elseif ($pendingRemote > 0) {
            $warnings[] = $pendingRemote . ' backup(s) have no verified remote copy yet.';
        }
        try {
            BackupConfig::tool('pg_dump');
        } catch (RuntimeException $e) {
            $warnings[] = 'pg_dump was not found. Set PG_BIN_DIR in .env.';
        }

        return [
            'base_dir' => BackupConfig::baseDir(),
            'running' => OperationLock::running(),
            'last_run' => $lastRun,
            'last_local' => $lastLocal,
            'last_full' => $lastFull,
            'remote' => $remoteInfo,
            'pending_remote' => $pendingRemote,
            'counts' => [
                'daily' => count(array_filter($files, static fn($f) => $f['kind'] === 'daily')),
                'weekly' => count(array_filter($files, static fn($f) => $f['kind'] === 'weekly')),
                'total' => count($files),
                'bytes' => $totalSize,
            ],
            'restore_test' => $lastTest,
            'warnings' => $warnings,
        ];
    }
}

/** A backup step failed in a way that has a safe, user-readable message. */
final class BackupFailure extends RuntimeException
{
    public string $codeName;

    public function __construct(string $message, string $codeName = 'FAILED')
    {
        parent::__construct($message);
        $this->codeName = $codeName;
    }
}
