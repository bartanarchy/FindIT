<?php

/**
 * Encrypted backup for FindIT. The backup lives OUTSIDE the database, so it
 * survives a dropped database, deleted rows or a wiped table.
 *
 * 1. Journal  (storage/backup/journal.log)
 *    Every time the app writes to the database (registration, item report,
 *    claim, admin decision ...) the full saved row is appended here as one
 *    encrypted line. The file is append-only and hash-chained: removing or
 *    editing a line is detected by scripts/verify-backup.php.
 *
 * 2. Snapshots (storage/backup/snapshot-*.fbk)
 *    A full encrypted copy of every table, made by scripts/backup-snapshot.php.
 *
 * 3. Restore (scripts/restore.php) = newest snapshot + journal entries after it.
 *
 * Everything is encrypted with a key derived from APP_ENC_KEY, so a stolen
 * backup folder is unreadable without the key.
 */
final class Backup
{
    /** Tables in restore order (parents before children). */
    public const TABLES = [
        'category' => ['table' => 'public.category', 'pk' => 'id_category', 'serials' => ['id_category']],
        'location' => ['table' => 'public.location', 'pk' => 'id_location', 'serials' => ['id_location']],
        'admin' => ['table' => 'public.admin', 'pk' => 'id_admin', 'serials' => ['id_admin']],
        'User' => ['table' => 'public."User"', 'pk' => 'id_user', 'serials' => ['id_user']],
        'item' => ['table' => 'public.item', 'pk' => 'kode_laporan', 'serials' => ['kode_laporan', 'id_item']],
        'claim' => ['table' => 'public.claim', 'pk' => 'id_claim', 'serials' => ['id_claim']],
        'notification' => ['table' => 'public.notification', 'pk' => 'id_notification', 'serials' => ['id_notification']],
    ];

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    // ------------------------------------------------------------- location

    public static function dir(): string
    {
        $dir = (defined('BACKUP_PATH') && BACKUP_PATH !== '')
            ? BACKUP_PATH
            : dirname(__DIR__, 2) . '/storage/backup';

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Backup folder could not be created: ' . $dir);
        }

        // Belt and braces: never let a web server serve this folder.
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }

        return rtrim($dir, '/\\');
    }

    private static function logFile(): string
    {
        return self::dir() . '/journal.log';
    }

    // -------------------------------------------------------------- writing

    /**
     * Save a copy of one row to the journal. Call this AFTER the database
     * write has been committed. It never throws: a backup problem is logged
     * and must not break the user's request.
     */
    public static function record(PDO $pdo, string $logical, $id, string $action = 'upsert'): bool
    {
        try {
            $spec = self::spec($logical);
            $row = self::fetchRow($pdo, $spec, $id);

            if ($row === null) {
                error_log('[FindIT] Backup: row not found for ' . $logical . ' #' . $id);
                return false;
            }

            self::append([
                't' => $logical,
                'a' => $action,
                'id' => $id,
                'row' => self::encodeRow($row),
            ]);

            return true;
        } catch (Throwable $e) {
            error_log('[FindIT] Backup journal write failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Journal several rows of the same table. */
    public static function recordMany(PDO $pdo, string $logical, array $ids, string $action = 'upsert'): void
    {
        foreach (array_unique($ids) as $id) {
            self::record($pdo, $logical, $id, $action);
        }
    }

    private static function spec(string $logical): array
    {
        if (!isset(self::TABLES[$logical])) {
            throw new InvalidArgumentException('Unknown table: ' . $logical);
        }
        return self::TABLES[$logical];
    }

    private static function fetchRow(PDO $pdo, array $spec, $id): ?array
    {
        // $spec comes from the constant list above, never from user input.
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . $spec['table'] . ' WHERE ' . $spec['pk'] . ' = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Make a row JSON-safe (bytea streams become base64). */
    public static function encodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_resource($value)) {
                $row[$column] = ['__b64' => base64_encode((string) stream_get_contents($value))];
            }
        }
        return $row;
    }

    /** Append one encrypted, hash-chained line to the journal (process-safe). */
    private static function append(array $entry): void
    {
        $dir = self::dir();
        $logFile = self::logFile();

        $lock = fopen($dir . '/journal.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the journal.');
        }

        try {
            $state = self::loadState($logFile);
            $seq = $state['seq'] + 1;

            $payload = ['seq' => $seq, 'ts' => gmdate('c'), 'prev' => $state['hash']] + $entry;
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $line = Crypto::sealBackup($json, 'journal');

            $fh = fopen($logFile, 'ab');
            if ($fh === false) {
                throw new RuntimeException('Could not open the journal.');
            }
            $written = fwrite($fh, $line . "\n");
            fflush($fh);
            fclose($fh);

            if ($written === false || $written < strlen($line) + 1) {
                throw new RuntimeException('Journal write was incomplete.');
            }

            clearstatcache(true, $logFile);
            self::saveState([
                'seq' => $seq,
                'hash' => hash('sha256', $line),
                'size' => filesize($logFile),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function stateFile(): string
    {
        return self::dir() . '/journal.state';
    }

    private static function saveState(array $state): void
    {
        $tmp = self::stateFile() . '.tmp';
        file_put_contents($tmp, json_encode($state), LOCK_EX);
        rename($tmp, self::stateFile());
    }

    /** Last sequence number + hash. Rebuilt from the log if the cache is stale. */
    private static function loadState(string $logFile): array
    {
        clearstatcache(true, $logFile);
        $size = is_file($logFile) ? filesize($logFile) : 0;

        if ($size === 0) {
            return ['seq' => 0, 'hash' => self::GENESIS, 'size' => 0];
        }

        $cached = is_file(self::stateFile())
            ? json_decode((string) file_get_contents(self::stateFile()), true)
            : null;

        if (is_array($cached) && ($cached['size'] ?? -1) === $size) {
            return $cached;
        }

        // Cache missing or out of date: recover from the last line of the log.
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $last = end($lines);
        $data = json_decode(Crypto::openBackup($last, 'journal'), true);

        return ['seq' => (int) $data['seq'], 'hash' => hash('sha256', $last), 'size' => $size];
    }

    // -------------------------------------------------------------- reading

    /**
     * Read the whole journal. Yields decrypted entries and checks the hash
     * chain, so a deleted or altered line raises an exception.
     */
    public static function readJournal(): Generator
    {
        $logFile = self::logFile();
        if (!is_file($logFile)) {
            return;
        }

        $fh = fopen($logFile, 'rb');
        $prev = self::GENESIS;
        $expectedSeq = 1;
        $lineNo = 0;

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $lineNo++;

                $entry = json_decode(Crypto::openBackup($line, 'journal'), true);

                if (!is_array($entry) || ($entry['prev'] ?? '') !== $prev || (int) $entry['seq'] !== $expectedSeq) {
                    throw new RuntimeException(
                        'Journal chain is broken at line ' . $lineNo . ' (a line was removed or changed).'
                    );
                }

                $prev = hash('sha256', $line);
                $expectedSeq++;
                yield $entry;
            }
        } finally {
            fclose($fh);
        }
    }

    public static function lastSeq(): int
    {
        return self::loadState(self::logFile())['seq'];
    }

    // ------------------------------------------------------------ snapshots

    /** Create a full encrypted snapshot of every table. Returns the file path. */
    public static function snapshot(PDO $pdo, int $keep = 30): string
    {
        $journalSeq = self::lastSeq();
        $tables = [];

        // One consistent point-in-time view of all tables.
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            foreach (self::TABLES as $logical => $spec) {
                $stmt = $pdo->query('SELECT * FROM ' . $spec['table'] . ' ORDER BY ' . $spec['pk']);
                $rows = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rows[] = self::encodeRow($row);
                }
                $tables[$logical] = $rows;
            }
        } finally {
            $pdo->exec('COMMIT');
        }

        $json = json_encode(
            ['created' => gmdate('c'), 'journal_seq' => $journalSeq, 'tables' => $tables],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $path = self::dir() . '/snapshot-' . gmdate('Ymd-His') . '.fbk';
        $tmp = $path . '.tmp';
        file_put_contents($tmp, Crypto::sealBackup($json, 'snapshot'), LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, $path);

        // Keep only the newest snapshots.
        $all = self::snapshotFiles();
        foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
            @unlink($old);
        }

        return $path;
    }

    /** Snapshot files, oldest first. */
    public static function snapshotFiles(): array
    {
        $files = glob(self::dir() . '/snapshot-*.fbk') ?: [];
        sort($files);
        return $files;
    }

    public static function loadSnapshot(string $path): array
    {
        $data = json_decode(Crypto::openBackup((string) file_get_contents($path), 'snapshot'), true);
        if (!is_array($data) || !isset($data['tables'])) {
            throw new RuntimeException('Snapshot is invalid: ' . $path);
        }
        return $data;
    }

    // -------------------------------------------------------------- restore

    /** Insert or update one row exactly as it was backed up. */
    public static function upsertRow(PDO $pdo, string $logical, array $row): void
    {
        $spec = self::spec($logical);
        $columns = array_keys($row);

        $quoted = array_map(static fn($c) => '"' . str_replace('"', '""', $c) . '"', $columns);
        $params = array_map(static fn($i) => ':p' . $i, array_keys($columns));
        $pk = '"' . $spec['pk'] . '"';

        $updates = [];
        foreach ($columns as $i => $column) {
            if ($column !== $spec['pk']) {
                $updates[] = $quoted[$i] . ' = EXCLUDED.' . $quoted[$i];
            }
        }

        $sql = 'INSERT INTO ' . $spec['table'] . ' (' . implode(', ', $quoted) . ') '
            . 'OVERRIDING SYSTEM VALUE VALUES (' . implode(', ', $params) . ') '
            . 'ON CONFLICT (' . $pk . ') DO UPDATE SET ' . implode(', ', $updates);

        $stmt = $pdo->prepare($sql);

        foreach (array_values($row) as $i => $value) {
            $name = ':p' . $i;

            if ($value === null) {
                $stmt->bindValue($name, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } elseif (is_array($value) && isset($value['__b64'])) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, (string) base64_decode($value['__b64'], true));
                rewind($stream);
                $stmt->bindValue($name, $stream, PDO::PARAM_LOB);
            } else {
                $stmt->bindValue($name, (string) $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
    }

    /** Move every ID sequence past the highest restored ID. */
    public static function fixSequences(PDO $pdo): void
    {
        foreach (self::TABLES as $spec) {
            foreach ($spec['serials'] as $column) {
                $seq = $pdo->query(
                    "SELECT pg_get_serial_sequence('" . $spec['table'] . "', '" . $column . "')"
                )->fetchColumn();

                if ($seq) {
                    $pdo->exec(
                        "SELECT setval('" . $seq . "', COALESCE((SELECT MAX(\"" . $column . "\") FROM "
                        . $spec['table'] . "), 0) + 1, false)"
                    );
                }
            }
        }
    }
}
