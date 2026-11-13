<?php

/**
 * File based records: one JSON file per backup/restore run plus text logs.
 * Everything lives in the backup directory, NOT in the database, so history and
 * recovery still work when the database is gone.
 */
final class BackupStore
{
    private const LOG_MAX_BYTES = 5 * 1024 * 1024;

    public static function newRunId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }

    public static function validRunId(string $id): bool
    {
        return preg_match('/\A\d{8}-\d{6}-[a-f0-9]{4}\z/', $id) === 1;
    }

    public static function now(): string
    {
        return date('c');
    }

    private static function runPath(string $id): string
    {
        if (!self::validRunId($id)) {
            throw new InvalidArgumentException('Invalid run id.');
        }
        return BackupConfig::dir('logs/runs') . '/' . $id . '.json';
    }

    /** Save a run record atomically (write a temp file, then rename). */
    public static function saveRun(array $run): void
    {
        $path = self::runPath((string) $run['id']);
        $tmp = $path . '.tmp';

        $json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('The backup record could not be saved.');
        }

        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($path);
            rename($tmp, $path);
        }
    }

    public static function loadRun(string $id): ?array
    {
        if (!self::validRunId($id)) {
            return null;
        }
        $path = self::runPath($id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** Newest first. */
    public static function listRuns(int $limit = 300): array
    {
        $files = glob(BackupConfig::dir('logs/runs') . '/*.json') ?: [];
        rsort($files, SORT_STRING);

        $runs = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $runs[] = $data;
            }
        }
        return $runs;
    }

    /** Update one run safely (reload, change, save). */
    public static function updateRun(string $id, callable $change): ?array
    {
        $run = self::loadRun($id);
        if ($run === null) {
            return null;
        }
        $run = $change($run) ?? $run;
        self::saveRun($run);
        return $run;
    }

    // ------------------------------------------------------ restore records

    public static function saveRestore(array $restore): void
    {
        if (!self::validRunId((string) $restore['id'])) {
            throw new InvalidArgumentException('Invalid restore id.');
        }

        $path = BackupConfig::dir('logs/restores') . '/' . $restore['id'] . '.json';
        $tmp = $path . '.tmp';
        $json = json_encode($restore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('The restore record could not be saved.');
        }

        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($path);
            rename($tmp, $path);
        }
    }

    /** Newest first. */
    public static function listRestores(int $limit = 50): array
    {
        $files = glob(BackupConfig::dir('logs/restores') . '/*.json') ?: [];
        rsort($files, SORT_STRING);

        $items = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $items[] = $data;
            }
        }
        return $items;
    }

    /** Append one JSON line to logs/<channel>.log. Never throws. */
    public static function log(string $channel, string $level, string $event, array $context = []): void
    {
        try {
            if (!in_array($channel, ['backup', 'restore'], true)) {
                return;
            }

            $file = BackupConfig::dir('logs') . '/' . $channel . '.log';

            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) > self::LOG_MAX_BYTES) {
                @rename($file, $file . '.1');
            }

            $line = json_encode(
                ['time' => date('c'), 'pid' => getmypid(), 'level' => $level, 'event' => $event] + $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('[FindIT] Backup log write failed: ' . $e->getMessage());
        }
    }
}
