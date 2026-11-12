<?php

/**
 * Restores the FindIT database from a backup file.
 *
 * Safety rules built into the procedure:
 *  - The backup is restored into a NEW temporary database first and checked
 *    (tables, data, relations). Your real database is not touched until that works.
 *  - Only then the real database is RENAMED to "<name>_old_<time>" (never dropped)
 *    and the restored one takes its name. If anything fails, nothing was lost.
 *  - The source backup file is never changed or deleted.
 *  - Only one backup/restore can run at a time (OperationLock).
 *  - The typed confirmation must equal the database name.
 *  - File names are validated; no path or SQL text comes from the user.
 *
 * It needs only .env and the backup folder, so it works when the main database is gone.
 */
final class RestoreManager
{
    // ======================================================================
    //  Database state and connections
    // ======================================================================

    private static function connect(string $database, string $role = 'restore'): PDO
    {
        BackupManager::assertPlainName($database);
        $creds = BackupConfig::creds($role);

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', BackupConfig::dbHost(), BackupConfig::dbPort(), $database),
            $creds['user'],
            $creds['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );
    }

    private static function admin(): PDO
    {
        return self::connect(BackupConfig::env('MAINTENANCE_DB', 'postgres'));
    }

    private static function ident(string $name): string
    {
        BackupManager::assertPlainName($name);
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * What condition is the main database in?
     *
     * @return array{state:string,message:string}
     *   HEALTHY | DAMAGED | MISSING | SERVER_DOWN | AUTH_FAILED | ERROR
     */
    public static function targetState(?string $database = null): array
    {
        $database = $database ?? BackupConfig::dbName();

        try {
            $admin = self::admin();
        } catch (Throwable $e) {
            $info = BackupManager::classifyPgError($e->getMessage());
            $state = $info['code'] === 'DB_UNREACHABLE' ? 'SERVER_DOWN' : ($info['code'] === 'DB_AUTH' ? 'AUTH_FAILED' : 'ERROR');
            return ['state' => $state, 'message' => $info['message']];
        }

        $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = :d');
        $exists->execute(['d' => $database]);

        if (!$exists->fetchColumn()) {
            return ['state' => 'MISSING', 'message' => 'The database "' . $database . '" does not exist.'];
        }

        try {
            $pdo = self::connect($database);
            foreach (BackupManager::EXPECTED_TABLES as $table) {
                $pdo->query('SELECT 1 FROM public."' . $table . '" LIMIT 1');
            }
            return ['state' => 'HEALTHY', 'message' => 'The database exists and all tables can be read.'];
        } catch (Throwable $e) {
            return ['state' => 'DAMAGED', 'message' => 'The database exists but tables are missing or unreadable.'];
        }
    }

    // ======================================================================
    //  Which backups can be restored
    // ======================================================================

    /**
     * Local backup files, plus files that exist only on the remote copy.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function candidates(bool $includeRemote = true): array
    {
        $items = [];
        foreach (BackupManager::listLocalBackups() as $file) {
            $file['source'] = 'local';
            $items[] = $file;
        }

        if ($includeRemote && RemoteFactory::isConfigured()) {
            try {
                $driver = RemoteFactory::create();
                $have = array_map(static fn($f) => $f['kind'] . '/' . $f['name'], $items);

                foreach (['daily', 'weekly'] as $kind) {
                    foreach ($driver->listFiles($kind) as $remoteFile) {
                        if (in_array($kind . '/' . $remoteFile['name'], $have, true)) {
                            continue;
                        }
                        $items[] = [
                            'kind' => $kind,
                            'name' => $remoteFile['name'],
                            'size' => $remoteFile['size'],
                            'mtime' => $remoteFile['mtime'],
                            'has_checksum' => true,
                            'type' => $kind,
                            'validation' => 'NOT_RUN',
                            'remote' => 'SUCCESS',
                            'run_id' => null,
                            'source' => 'remote',
                        ];
                    }
                }
            } catch (Throwable $e) {
                BackupStore::log('restore', 'warning', 'remote_list_failed', ['detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
            }
        }

        usort($items, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /** Most recent restore (or null). */
    public static function lastRestore(): ?array
    {
        foreach (BackupStore::listRestores(20) as $item) {
            if (($item['mode'] ?? '') === 'main') {
                return $item;
            }
        }
        return null;
    }

    // ======================================================================
    //  Restoring the main database
    // ======================================================================

    /**
     * @param array $opts kind (daily|weekly), name, source (local|remote), by, confirm
     * @return array restore record (status SUCCESS or FAILED, steps, error)
     */
    public static function restoreMain(array $opts): array
    {
        BackupConfig::applyTimezone();
        $database = BackupConfig::dbName();

        $rec = self::newRecord('main', $database, $opts);

        // Explicit confirmation: the exact database name must be typed.
        if (!isset($opts['confirm']) || !hash_equals($database, (string) $opts['confirm'])) {
            return self::abort($rec, 'The confirmation text does not match the database name. Nothing was changed.');
        }

        $lock = OperationLock::acquire('restore', $rec['id']);
        if ($lock === null) {
            return self::abort($rec, 'Another backup or restore is already running. Try again when it has finished.');
        }

        $tmpDb = null;
        $tmpFile = null;

        try {
            BackupStore::saveRestore($rec);
            BackupStore::log('restore', 'info', 'restore_started', ['restore' => $rec['id'], 'file' => $rec['file'], 'source' => $rec['source'], 'by' => $rec['requested_by']]);

            // 1. state of the target database
            $state = self::targetState($database);
            $rec['state_before'] = $state['state'];
            self::step($rec, 'Check the database', in_array($state['state'], ['SERVER_DOWN', 'AUTH_FAILED', 'ERROR'], true) ? 'FAILED' : 'OK', $state['state'] . ': ' . $state['message']);
            if (in_array($state['state'], ['SERVER_DOWN', 'AUTH_FAILED', 'ERROR'], true)) {
                throw new BackupFailure($state['message'], 'DB_STATE');
            }

            // 2. the backup file: found, checksum, readable by pg_restore
            [$path, $tmpFile, $expectedSha, $run] = self::locateFile($rec, $opts);
            $info = self::verifyFile($path, $expectedSha);
            self::step($rec, 'Verify the backup file', $info['ok'] ? 'OK' : 'FAILED', $info['message']);
            if (!$info['ok']) {
                throw new BackupFailure($info['message'], 'VERIFY');
            }
            foreach ($info['warnings'] as $warning) {
                $rec['warnings'][] = $warning;
            }

            // 3. keep a copy of what exists now (the old database is also kept by renaming it)
            if (in_array($state['state'], ['HEALTHY', 'DAMAGED'], true)) {
                $safety = self::safetyDump($database);
                self::step($rec, 'Save the current database to a file', $safety['ok'] ? 'OK' : 'SKIPPED', $safety['message']);
                if ($safety['ok']) {
                    $rec['safety_backup'] = $safety['file'];
                }
            } else {
                self::step($rec, 'Save the current database to a file', 'SKIPPED', 'There is no database to save.');
            }

            // 4. restore into a new temporary database and check it
            $tmpDb = self::tempName($database);
            $admin = self::admin();
            $built = self::buildDatabase($admin, $tmpDb, $path, $run['table_counts'] ?? null, $rec);
            if (!$built['ok']) {
                throw new BackupFailure($built['message'], 'RESTORE');
            }
            $rec['counts'] = $built['counts'];

            // 5. swap names (the old database is kept)
            $swap = self::swap($admin, $database, $tmpDb, $state['state'] !== 'MISSING');
            self::step($rec, 'Put the restored database in place', $swap['ok'] ? 'OK' : 'FAILED', $swap['message']);
            if (!$swap['ok']) {
                throw new BackupFailure($swap['message'], 'SWAP');
            }
            $tmpDb = null; // it is now the real database
            $rec['old_database'] = $swap['old'];

            $rec['status'] = 'SUCCESS';
        } catch (BackupFailure $e) {
            $rec['status'] = 'FAILED';
            $rec['error'] = $e->getMessage();
        } catch (Throwable $e) {
            BackupStore::log('restore', 'error', 'unexpected_error', ['restore' => $rec['id'], 'detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
            $rec['status'] = 'FAILED';
            $rec['error'] = 'An unexpected error stopped the restore. Your database was not replaced. See logs/restore.log.';
        }

        // Clean up only OUR temporary artifacts.
        if ($tmpDb !== null) {
            self::dropTemp($tmpDb);
        }
        if ($tmpFile !== null) {
            @unlink($tmpFile);
            @unlink($tmpFile . '.sha256');
        }

        $rec = self::finish($rec);
        $lock->release();

        if ($rec['status'] === 'SUCCESS' && !empty($rec['run_id'])) {
            BackupStore::updateRun($rec['run_id'], static function (array $run) use ($rec) {
                $run['restores'][] = ['id' => $rec['id'], 'at' => $rec['finished_at'], 'by' => $rec['requested_by'], 'status' => 'SUCCESS', 'database' => $rec['database']];
                return $run;
            });
        }

        return $rec;
    }

    // ======================================================================
    //  Test restore (throw-away database)
    // ======================================================================

    /**
     * Restore a backup into the test database and check it. Safe to run any time:
     * it can only ever touch RESTORE_TEST_DB, never the real database.
     *
     * @param array $opts kind, name, source (optional: defaults to the newest local backup), by
     */
    public static function testRestore(array $opts = []): array
    {
        BackupConfig::applyTimezone();
        $testDb = BackupConfig::restoreTestDb();

        if (empty($opts['name'])) {
            $newest = null;
            foreach (BackupManager::listLocalBackups() as $file) {
                $newest = $file;
                break;
            }
            if ($newest === null) {
                $rec = self::newRecord('test', $testDb, $opts);
                return self::abort($rec, 'There is no backup to test yet.');
            }
            $opts['kind'] = $newest['kind'];
            $opts['name'] = $newest['name'];
            $opts['source'] = 'local';
        }

        $rec = self::newRecord('test', $testDb, $opts);

        $lock = OperationLock::acquire('restore', $rec['id']);
        if ($lock === null) {
            return self::abort($rec, 'Another backup or restore is already running.');
        }

        $tmpFile = null;

        try {
            BackupStore::saveRestore($rec);
            BackupStore::log('restore', 'info', 'restore_test_started', ['restore' => $rec['id'], 'file' => $rec['file']]);

            [$path, $tmpFile, $expectedSha, $run] = self::locateFile($rec, $opts);
            $info = self::verifyFile($path, $expectedSha);
            self::step($rec, 'Verify the backup file', $info['ok'] ? 'OK' : 'FAILED', $info['message']);
            if (!$info['ok']) {
                throw new BackupFailure($info['message'], 'VERIFY');
            }

            $admin = self::admin();
            self::dropDatabase($admin, $testDb);
            self::step($rec, 'Clear the test database', 'OK', $testDb);

            $built = self::buildDatabase($admin, $testDb, $path, $run['table_counts'] ?? null, $rec);
            if (!$built['ok']) {
                throw new BackupFailure($built['message'], 'RESTORE');
            }
            $rec['counts'] = $built['counts'];
            $rec['status'] = 'SUCCESS';
        } catch (BackupFailure $e) {
            $rec['status'] = 'FAILED';
            $rec['error'] = $e->getMessage();
        } catch (Throwable $e) {
            BackupStore::log('restore', 'error', 'unexpected_error', ['restore' => $rec['id'], 'detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
            $rec['status'] = 'FAILED';
            $rec['error'] = 'An unexpected error stopped the test. See logs/restore.log.';
        }

        if ($tmpFile !== null) {
            @unlink($tmpFile);
            @unlink($tmpFile . '.sha256');
        }

        $rec = self::finish($rec);
        $lock->release();

        @file_put_contents(BackupConfig::dir('logs') . '/restore-test.json', json_encode([
            'id' => $rec['id'],
            'tested_at' => $rec['finished_at'],
            'file' => $rec['file'],
            'ok' => $rec['status'] === 'SUCCESS',
            'message' => $rec['status'] === 'SUCCESS' ? 'The backup was restored and checked successfully.' : $rec['error'],
            'counts' => $rec['counts'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $rec;
    }

    /** Remove the throw-away test database (only that one). */
    public static function dropTestDatabase(): void
    {
        self::dropDatabase(self::admin(), BackupConfig::restoreTestDb());
    }

    // ======================================================================
    //  Building blocks
    // ======================================================================

    private static function newRecord(string $mode, string $database, array $opts): array
    {
        $kind = (string) ($opts['kind'] ?? '');
        $name = (string) ($opts['name'] ?? '');

        return [
            'id' => BackupStore::newRunId(),
            'mode' => $mode,
            'status' => 'RUNNING',
            'database' => $database,
            'file' => $kind !== '' && $name !== '' ? $kind . '/' . $name : null,
            'source' => ($opts['source'] ?? 'local') === 'remote' ? 'remote' : 'local',
            'requested_by' => isset($opts['by']) ? substr((string) $opts['by'], 0, 80) : null,
            'started_at' => BackupStore::now(),
            'finished_at' => null,
            'state_before' => null,
            'steps' => [],
            'warnings' => [],
            'counts' => null,
            'old_database' => null,
            'safety_backup' => null,
            'run_id' => null,
            'error' => null,
        ];
    }

    private static function step(array &$rec, string $name, string $status, string $detail = ''): void
    {
        $rec['steps'][] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'at' => BackupStore::now()];
        BackupStore::log('restore', $status === 'FAILED' ? 'error' : 'info', 'step', ['restore' => $rec['id'], 'step' => $name, 'status' => $status, 'detail' => ProcessRunner::redact($detail, BackupManager::secrets())]);
    }

    private static function finish(array $rec): array
    {
        $rec['finished_at'] = BackupStore::now();
        try {
            BackupStore::saveRestore($rec);
        } catch (Throwable $e) {
            error_log('[FindIT] Could not save restore record: ' . $e->getMessage());
        }
        BackupStore::log('restore', $rec['status'] === 'SUCCESS' ? 'info' : 'error', 'restore_finished', [
            'restore' => $rec['id'], 'mode' => $rec['mode'], 'status' => $rec['status'], 'file' => $rec['file'], 'error' => $rec['error'],
        ]);
        return $rec;
    }

    private static function abort(array $rec, string $message): array
    {
        $rec['status'] = 'FAILED';
        $rec['error'] = $message;
        $rec['finished_at'] = BackupStore::now();
        try {
            BackupStore::saveRestore($rec);
        } catch (Throwable $e) {
        }
        BackupStore::log('restore', 'warning', 'restore_refused', ['restore' => $rec['id'], 'reason' => $message, 'by' => $rec['requested_by']]);
        return $rec;
    }

    /**
     * Find the file to restore. For a remote file it is first copied to the tmp folder.
     *
     * @return array{0:string,1:?string,2:?string,3:array} path, temp file to delete, expected sha256, run record
     */
    private static function locateFile(array &$rec, array $opts): array
    {
        $kind = (string) ($opts['kind'] ?? '');
        $name = (string) ($opts['name'] ?? '');
        RemoteFactory::assertValid($name, $kind); // rejects path tricks and odd names

        $run = [];
        foreach (BackupStore::listRuns(500) as $candidate) {
            if (($candidate['file'] ?? null) === $kind . '/' . $name) {
                $run = $candidate;
                $rec['run_id'] = $candidate['id'];
                break;
            }
        }

        if (($opts['source'] ?? 'local') === 'remote') {
            $driver = RemoteFactory::create();
            if ($driver === null) {
                throw new BackupFailure('No remote copy is configured.', 'REMOTE');
            }

            $tmp = BackupConfig::dir('tmp') . '/restore_' . $rec['id'] . '_' . $name;
            try {
                $driver->download($name, $kind, $tmp);
                $driver->download($name . '.sha256', $kind, $tmp . '.sha256');
            } catch (Throwable $e) {
                @unlink($tmp);
                @unlink($tmp . '.sha256');
                throw new BackupFailure('The file could not be fetched from the remote copy.', 'REMOTE');
            }

            $expected = self::readChecksum($tmp . '.sha256');
            self::step($rec, 'Fetch the backup from the remote copy', 'OK', $kind . '/' . $name);
            return [$tmp, $tmp, $expected, $run];
        }

        try {
            $path = BackupManager::resolveLocal($kind, $name);
        } catch (Throwable $e) {
            self::step($rec, 'Find the backup file', 'FAILED', 'The backup file was not found.');
            throw new BackupFailure('The backup file was not found.', 'NOT_FOUND');
        }

        $expected = self::readChecksum($path . '.sha256') ?? ($run['sha256'] ?? null);
        self::step($rec, 'Find the backup file', 'OK', $kind . '/' . $name);

        return [$path, null, $expected, $run];
    }

    private static function readChecksum(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $first = strtok((string) file_get_contents($file), " \t\r\n");
        return $first !== false && preg_match('/\A[a-f0-9]{64}\z/', $first) ? $first : null;
    }

    /** @return array{ok:bool,message:string,warnings:string[]} */
    public static function verifyFile(string $path, ?string $expectedSha): array
    {
        $warnings = [];

        if ($expectedSha === null) {
            $warnings[] = 'No checksum was recorded for this file, so it could only be checked by reading it.';
        } elseif (!hash_equals($expectedSha, (string) hash_file('sha256', $path))) {
            return ['ok' => false, 'message' => 'The file does not match its checksum. It was changed or damaged.', 'warnings' => []];
        }

        $check = BackupManager::validateDump($path);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'], 'warnings' => []];
        }

        return [
            'ok' => true,
            'message' => ($expectedSha === null ? 'Readable' : 'Checksum matches') . '; ' . $check['message'],
            'warnings' => $warnings,
        ];
    }

    private static function tempName(string $database): string
    {
        $name = $database . '_restoring_' . date('YmdHis');
        BackupManager::assertPlainName($name);
        return $name;
    }

    /** Dump the database that is about to be replaced into daily/ (best effort). */
    private static function safetyDump(string $database): array
    {
        try {
            $name = 'findit_pre-restore_' . date('Y-m-d_His') . '.dump';
            $final = BackupConfig::dir('daily') . '/' . $name;
            $part = BackupConfig::dir('daily') . '/.' . $name . '.part';

            $result = BackupManager::dumpTo($part, $database, 'restore');
            if (!$result['ok']) {
                return ['ok' => false, 'message' => 'Skipped (' . $result['message'] . ') The old database is kept by renaming it.', 'file' => null];
            }

            $sha = (string) hash_file('sha256', $part);
            rename($part, $final);
            @chmod($final, 0600);
            file_put_contents($final . '.sha256', $sha . '  ' . $name . "\n");

            $run = [
                'id' => BackupStore::newRunId(),
                'type' => 'pre-restore',
                'trigger' => 'restore',
                'started_at' => BackupStore::now(),
                'finished_at' => BackupStore::now(),
                'database' => $database,
                'status' => 'SUCCESS',
                'file' => 'daily/' . $name,
                'size' => (int) filesize($final),
                'sha256' => $sha,
                'validation' => ['status' => 'NOT_RUN', 'tables' => 0, 'message' => ''],
                'local' => ['status' => 'SUCCESS', 'error' => null],
                'remote' => ['driver' => 'none', 'status' => 'NOT_CONFIGURED', 'attempts' => 0],
                'error' => null,
                'restores' => [],
            ];
            BackupStore::saveRun($run);

            return ['ok' => true, 'message' => 'daily/' . $name, 'file' => 'daily/' . $name];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Skipped (could not write the file). The old database is kept by renaming it.', 'file' => null];
        }
    }

    /**
     * Create $database, load the backup into it and check the result.
     *
     * @return array{ok:bool,message:string,counts:?array}
     */
    private static function buildDatabase(PDO $admin, string $database, string $file, ?array $expectedCounts, array &$rec): array
    {
        $fail = static fn(string $m) => ['ok' => false, 'message' => $m, 'counts' => null];
        $q = self::ident($database);

        try {
            $admin->exec('CREATE DATABASE ' . $q . " ENCODING 'UTF8' TEMPLATE template0");
            self::step($rec, 'Create a new empty database', 'OK', $database);
        } catch (Throwable $e) {
            $info = BackupManager::classifyPgError($e->getMessage());
            self::step($rec, 'Create a new empty database', 'FAILED', $info['message']);
            return $fail($info['code'] === 'DB_PERMISSION'
                ? 'The restore account may not create databases. Set RESTORE_DB_USER / RESTORE_DB_PASS in .env.'
                : $info['message']);
        }

        // load the data
        try {
            $pgRestore = BackupConfig::tool('pg_restore');
        } catch (RuntimeException $e) {
            self::dropTemp($database);
            return $fail($e->getMessage());
        }

        $creds = BackupConfig::creds('restore');
        $result = ProcessRunner::run([
            $pgRestore,
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--no-password',
            '--host=' . BackupConfig::dbHost(),
            '--port=' . BackupConfig::dbPort(),
            '--username=' . $creds['user'],
            '--dbname=' . $database,
            $file,
        ], ['PGPASSWORD' => $creds['pass']], 900);

        if ($result['exit'] !== 0) {
            $info = BackupManager::classifyPgError($result['stderr'], $result['timed_out']);
            BackupStore::log('restore', 'error', 'pg_restore_failed', ['restore' => $rec['id'], 'exit' => $result['exit'], 'detail' => ProcessRunner::redact($result['stderr'], BackupManager::secrets())]);
            self::step($rec, 'Load the backup into it', 'FAILED', $info['message']);
            self::dropTemp($database);
            return $fail('The backup could not be loaded: ' . $info['message']);
        }
        self::step($rec, 'Load the backup into it', 'OK', 'pg_restore finished without errors');

        // let the website's own database account use the restored tables
        $grants = self::grantToApp($database);
        self::step($rec, 'Give the website account access', $grants['ok'] ? 'OK' : 'SKIPPED', $grants['message']);

        // validate
        $check = self::validateRestored($database, $file, $expectedCounts);
        foreach ($check['warnings'] as $warning) {
            $rec['warnings'][] = $warning;
        }
        self::step($rec, 'Check tables, data and relations', $check['ok'] ? 'OK' : 'FAILED', $check['message']);

        if (!$check['ok']) {
            self::dropTemp($database);
            return $fail($check['message']);
        }

        return ['ok' => true, 'message' => $check['message'], 'counts' => $check['counts']];
    }

    private static function grantToApp(string $database): array
    {
        try {
            $app = BackupConfig::env('DB_USER', 'postgres');
            $restoreUser = BackupConfig::creds('restore')['user'];

            if ($app === $restoreUser) {
                return ['ok' => true, 'message' => 'The website uses the same account, nothing to grant.'];
            }

            $pdo = self::connect($database);
            $exists = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = :r');
            $exists->execute(['r' => $app]);
            if (!$exists->fetchColumn()) {
                return ['ok' => false, 'message' => 'The website account does not exist; nothing was granted.'];
            }

            $role = self::ident($app);
            $pdo->exec('GRANT USAGE ON SCHEMA public TO ' . $role);
            $pdo->exec('GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA public TO ' . $role);
            $pdo->exec('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ' . $role);

            return ['ok' => true, 'message' => 'Access given to ' . $app . ' (read, insert, update).'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not give access to the website account; grant it manually if the site cannot read data.'];
        }
    }

    /**
     * @return array{ok:bool,message:string,counts:?array,warnings:string[]}
     */
    private static function validateRestored(string $database, string $file, ?array $expectedCounts): array
    {
        $warnings = [];

        try {
            $pdo = self::connect($database);

            $counts = [];
            foreach (BackupManager::EXPECTED_TABLES as $table) {
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
            }

            // relations: every foreign key from the backup must exist and be valid
            $fkInDb = (int) $pdo->query("SELECT COUNT(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE c.contype = 'f' AND n.nspname = 'public'")->fetchColumn();
            $fkInvalid = (int) $pdo->query("SELECT COUNT(*) FROM pg_constraint WHERE contype = 'f' AND NOT convalidated")->fetchColumn();

            $list = ProcessRunner::run([BackupConfig::tool('pg_restore'), '--list', $file], [], 180);
            $fkInDump = preg_match_all('/\bFK CONSTRAINT\b/', $list['stdout']);

            if ($fkInvalid > 0 || $fkInDb < $fkInDump) {
                return ['ok' => false, 'message' => 'Relations between tables are incomplete (' . $fkInDb . ' of ' . $fkInDump . ' foreign keys valid).', 'counts' => $counts, 'warnings' => $warnings];
            }

            if ($counts['admin'] === 0) {
                $warnings[] = 'The admin table is empty in this backup.';
            }
            if ($counts['category'] === 0) {
                $warnings[] = 'The category table is empty in this backup.';
            }

            if (is_array($expectedCounts)) {
                foreach ($expectedCounts as $table => $count) {
                    if (isset($counts[$table]) && $counts[$table] !== (int) $count) {
                        $warnings[] = 'Table ' . $table . ' has ' . $counts[$table] . ' rows; ' . (int) $count . ' were counted when the backup was made.';
                    }
                }
            }

            $rows = array_sum($counts);
            return [
                'ok' => true,
                'message' => count($counts) . ' tables readable, ' . $rows . ' rows, ' . $fkInDb . ' relations valid.',
                'counts' => $counts,
                'warnings' => $warnings,
            ];
        } catch (Throwable $e) {
            BackupStore::log('restore', 'error', 'validation_error', ['detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
            return ['ok' => false, 'message' => 'The restored database could not be read back.', 'counts' => null, 'warnings' => $warnings];
        }
    }

    /**
     * Replace $main by $new without ever dropping $main.
     *
     * @return array{ok:bool,message:string,old:?string}
     */
    private static function swap(PDO $admin, string $main, string $new, bool $mainExists): array
    {
        $qMain = self::ident($main);
        $qNew = self::ident($new);

        if (!$mainExists) {
            try {
                $admin->exec('ALTER DATABASE ' . $qNew . ' RENAME TO ' . $qMain);
                return ['ok' => true, 'message' => 'The restored database is now "' . $main . '".', 'old' => null];
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => BackupManager::classifyPgError($e->getMessage())['message'], 'old' => null];
            }
        }

        $old = $main . '_old_' . date('YmdHis');
        $qOld = self::ident($old);
        $blocked = false;

        try {
            // keep the website out while the names are swapped
            $admin->exec('ALTER DATABASE ' . $qMain . ' WITH ALLOW_CONNECTIONS false');
            $blocked = true;

            $kick = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :d AND pid <> pg_backend_pid()');
            $kick->execute(['d' => $main]);
            usleep(500000);

            $admin->exec('ALTER DATABASE ' . $qMain . ' RENAME TO ' . $qOld);

            try {
                $admin->exec('ALTER DATABASE ' . $qNew . ' RENAME TO ' . $qMain);
            } catch (Throwable $e) {
                $admin->exec('ALTER DATABASE ' . $qOld . ' RENAME TO ' . $qMain); // put everything back
                $admin->exec('ALTER DATABASE ' . $qMain . ' WITH ALLOW_CONNECTIONS true');
                return ['ok' => false, 'message' => 'The names could not be swapped; the original database was put back.', 'old' => null];
            }

            $admin->exec('ALTER DATABASE ' . $qOld . ' WITH ALLOW_CONNECTIONS true');

            return [
                'ok' => true,
                'message' => 'The restored database is now "' . $main . '". The previous one was kept as "' . $old . '".',
                'old' => $old,
            ];
        } catch (Throwable $e) {
            if ($blocked) {
                try {
                    $admin->exec('ALTER DATABASE ' . $qMain . ' WITH ALLOW_CONNECTIONS true');
                } catch (Throwable $ignored) {
                }
            }
            BackupStore::log('restore', 'error', 'swap_failed', ['detail' => ProcessRunner::redact($e->getMessage(), BackupManager::secrets())]);
            return [
                'ok' => false,
                'message' => 'The database is in use and could not be replaced (close other programs that use it, then try again). Nothing was changed.',
                'old' => null,
            ];
        }
    }

    /** Remove a temporary database that THIS procedure created. */
    private static function dropTemp(string $database): void
    {
        try {
            if (strpos($database, '_restoring_') === false && $database !== BackupConfig::restoreTestDb()) {
                return; // never drop anything that is not ours
            }
            self::dropDatabase(self::admin(), $database);
        } catch (Throwable $e) {
            BackupStore::log('restore', 'warning', 'temp_cleanup_failed', ['database' => $database]);
        }
    }

    private static function dropDatabase(PDO $admin, string $database): void
    {
        $q = self::ident($database);
        $exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = :d');
        $exists->execute(['d' => $database]);
        if (!$exists->fetchColumn()) {
            return;
        }

        // Only throw-away databases may be dropped here.
        if (strpos($database, '_restoring_') === false && $database !== BackupConfig::restoreTestDb()) {
            throw new RuntimeException('Refusing to drop a database that is not a restore test database.');
        }

        $admin->exec('ALTER DATABASE ' . $q . ' WITH ALLOW_CONNECTIONS false');
        $kick = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :d AND pid <> pg_backend_pid()');
        $kick->execute(['d' => $database]);
        usleep(300000);
        $admin->exec('DROP DATABASE ' . $q);
    }
}
