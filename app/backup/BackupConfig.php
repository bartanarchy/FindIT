<?php

/**
 * Settings for the backup / restore system, read straight from .env.
 *
 * This class deliberately does NOT use config/config.php: backup, history and
 * recovery must keep working when the main database is down or deleted.
 */
final class BackupConfig
{
    /** Folders inside the backup directory that the system may create. */
    private const SUBDIRS = ['daily', 'logs', 'logs/runs', 'logs/restores', 'tmp'];

    private static ?array $env = null;
    private static array $toolCache = [];

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    // ----------------------------------------------------------------- .env

    public static function env(string $key, string $default = ''): string
    {
        if (self::$env === null) {
            $path = self::root() . '/.env';
            $parsed = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
            self::$env = is_array($parsed) ? $parsed : [];
        }

        $value = self::$env[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        $value = trim((string) $value);

        // Remove one pair of surrounding quotes, if present.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }

        return $value === '' ? $default : $value;
    }

    public static function int(string $key, int $default, int $min, int $max): int
    {
        $raw = self::env($key, (string) $default);
        $value = ctype_digit($raw) ? (int) $raw : $default;
        return max($min, min($max, $value));
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = strtolower(self::env($key, $default ? 'true' : 'false'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function applyTimezone(): void
    {
        $zone = self::env('APP_TIMEZONE', 'Asia/Jakarta');
        if (!@date_default_timezone_set($zone)) {
            date_default_timezone_set('UTC');
        }
    }

    // ----------------------------------------------------------------- paths

    /** The backup directory. It must live OUTSIDE the website folder. */
    public static function baseDir(): string
    {
        $dir = self::env('BACKUP_DIR');

        if ($dir === '') {
            $dir = self::isWindows()
                ? 'C:\\FindIT_Backups'
                : dirname(self::root()) . '/FindIT_Backups';
        }

        $dir = rtrim($dir, '/\\');
        self::assertOutsideWebsite($dir);

        return $dir;
    }

    /** Get (and create if needed) one of the known sub folders. */
    public static function dir(string $sub): string
    {
        if (!in_array($sub, self::SUBDIRS, true)) {
            throw new InvalidArgumentException('Unknown backup folder.');
        }

        $path = self::baseDir() . '/' . $sub;

        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('The backup folder could not be created: ' . $path);
        }

        return $path;
    }

    private static function assertOutsideWebsite(string $dir): void
    {
        $root = realpath(self::root());
        $probe = $dir;

        // The folder may not exist yet: test the nearest parent that does.
        while ($probe !== '' && !is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }

        $real = $probe !== '' ? realpath($probe) : false;

        if ($root === false || $real === false) {
            return;
        }

        $norm = static fn($p) => strtolower(rtrim(str_replace('\\', '/', $p), '/')) . '/';

        if (strpos($norm($real), $norm($root)) === 0) {
            throw new RuntimeException(
                'BACKUP_DIR must be outside the website folder (' . $root . '), '
                . 'otherwise backups could be downloaded through the web server.'
            );
        }
    }

    // -------------------------------------------------------------- database

    public static function dbHost(): string
    {
        return self::env('DB_HOST', '127.0.0.1');
    }

    public static function dbPort(): string
    {
        return self::env('DB_PORT', '5432');
    }

    /** The one database that backups are made from and restores replace. */
    public static function dbName(): string
    {
        return self::env('DB_NAME', 'FindIT');
    }

    /**
     * Database login for a job: 'backup' (read only is enough) or 'restore'
     * (needs the right to create databases). Falls back to the app login.
     *
     * @return array{user:string,pass:string}
     */
    public static function creds(string $role): array
    {
        $prefix = $role === 'restore' ? 'RESTORE_DB_' : 'BACKUP_DB_';
        $user = self::env($prefix . 'USER');

        if ($user !== '') {
            return ['user' => $user, 'pass' => self::env($prefix . 'PASS')];
        }

        return [
            'user' => self::env('DB_USER', 'postgres'),
            'pass' => self::env('DB_PASS'),
        ];
    }

    /** Name of the throw-away database used to test restores. */
    public static function restoreTestDb(): string
    {
        $name = self::env('RESTORE_TEST_DB', 'findit_restore_test');

        // Safety: never let a test restore touch the real database.
        if (!preg_match('/^[a-z0-9_]{3,40}$/', $name)
            || strpos($name, 'test') === false
            || strcasecmp($name, self::dbName()) === 0
        ) {
            throw new RuntimeException(
                'RESTORE_TEST_DB must be lowercase letters/digits/underscores, contain "test" '
                . 'and differ from DB_NAME.'
            );
        }

        return $name;
    }

    // ------------------------------------------------------------- retention

    public static function keepDailyDays(): int
    {
        return self::int('BACKUP_KEEP_DAILY_DAYS', 7, 1, 3650);
    }

    /** Skip a scheduled backup if a good one was made less than this many hours ago. */
    public static function minIntervalHours(): int
    {
        return self::int('BACKUP_MIN_INTERVAL_HOURS', 12, 0, 720);
    }

    // ----------------------------------------------------------------- tools

    /**
     * Full path of a PostgreSQL / OpenSSH program. Nothing is assumed to be on PATH:
     * PG_BIN_DIR is tried first, then the usual Windows install folders, then PATH.
     */
    public static function tool(string $name): string
    {
        if (isset(self::$toolCache[$name])) {
            return self::$toolCache[$name];
        }

        $exe = self::isWindows() ? $name . '.exe' : $name;
        $candidates = [];

        $pgTools = ['pg_dump', 'pg_restore', 'psql'];
        if (in_array($name, $pgTools, true)) {
            $configured = self::env('PG_BIN_DIR');
            if ($configured !== '') {
                $candidates[] = rtrim($configured, '/\\') . DIRECTORY_SEPARATOR . $exe;
            }

            if (self::isWindows()) {
                $patterns = [
                    'C:\\Program Files\\PostgreSQL\\*\\bin\\' . $exe,
                    'C:\\Program Files (x86)\\PostgreSQL\\*\\bin\\' . $exe,
                    'C:\\laragon\\bin\\postgresql\\*\\bin\\' . $exe,
                ];
                foreach ($patterns as $pattern) {
                    $found = glob($pattern) ?: [];
                    // Newest version first, so pg_dump is never older than the server.
                    usort($found, static fn($a, $b) => strnatcasecmp($b, $a));
                    $candidates = array_merge($candidates, $found);
                }
            } else {
                $found = glob('/usr/lib/postgresql/*/bin/' . $exe) ?: [];
                usort($found, static fn($a, $b) => strnatcasecmp($b, $a));
                $candidates = array_merge($candidates, $found);
            }
        } elseif (self::isWindows()) {
            $candidates[] = 'C:\\Windows\\System32\\OpenSSH\\' . $exe;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return self::$toolCache[$name] = $candidate;
            }
        }

        throw new RuntimeException(
            $name . ' was not found. Install it, or set PG_BIN_DIR in .env '
            . '(for PostgreSQL, the folder that contains pg_dump).'
        );
    }
}
