<?php

/**
 * Field-level encryption for FindIT.
 *
 * - Sensitive columns are encrypted by PHP *before* they reach PostgreSQL, so
 *   anyone who reads the database directly (pgAdmin, a stolen dump, SQL
 *   injection) only sees ciphertext.
 * - Algorithm: AES-256-GCM (authenticated). A tampered value fails to decrypt.
 * - The key lives in .env (APP_ENC_KEY), never in the database.
 * - Searchable columns (email, NIM) use a "blind index": an HMAC of the value
 *   stored in a separate column, so we can look a user up without decrypting.
 *
 * Stored format:  enc:v1:<base64( iv(12) | tag(16) | ciphertext )>
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /**
     * Encrypted columns, grouped by table: table => [primary key, [columns]].
     * The column name is also used as the "context" bound into each ciphertext,
     * so a value copied into a different column will not decrypt.
     */
    public const FIELD_MAP = [
        'User' => [
            'pk' => 'id_user',
            'table' => 'public."User"',
            'columns' => ['email_kampus', 'name_user', 'no_telp', 'id_nim'],
        ],
        'item' => [
            'pk' => 'kode_laporan',
            'table' => 'public.item',
            'columns' => ['nama_barang', 'detail_lokasi', 'ciri_umum', 'ciri_khusus'],
        ],
        'claim' => [
            'pk' => 'id_claim',
            'table' => 'public.claim',
            'columns' => [
                'lokasi_kehilangan', 'terakhir_di_lihat', 'ciri_khusus_claim',
                'catatan_admin', 'kode_pengambilan', 'lokasi_pengambilan',
            ],
        ],
        'notification' => [
            'pk' => 'id_notification',
            'table' => 'public.notification',
            'columns' => ['title', 'message'],
        ],
    ];

    private static ?string $masterKey = null;
    private static array $subKeys = [];

    /** All encrypted column names (flat list). */
    public static function fields(): array
    {
        $all = [];
        foreach (self::FIELD_MAP as $spec) {
            foreach ($spec['columns'] as $column) {
                $all[$column] = true;
            }
        }
        return array_keys($all);
    }

    // ---------------------------------------------------------------- keys

    /** Parse and validate a key string from .env. */
    public static function parseKey(string $raw): string
    {
        $raw = trim($raw);
        if (strpos($raw, 'base64:') === 0) {
            $raw = substr($raw, 7);
        }

        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'APP_ENC_KEY is missing or invalid. Run: php scripts/generate-key.php --write'
            );
        }

        return $key;
    }

    private static function master(): string
    {
        if (self::$masterKey === null) {
            $raw = defined('APP_ENC_KEY') ? APP_ENC_KEY : (string) getenv('APP_ENC_KEY');
            self::$masterKey = self::parseKey($raw);
        }
        return self::$masterKey;
    }

    /** Independent keys for each purpose, derived from the master key. */
    private static function subKey(string $purpose): string
    {
        if (!isset(self::$subKeys[$purpose])) {
            self::$subKeys[$purpose] = hash_hkdf(
                'sha256', self::master(), 32, 'findit:' . $purpose . ':v1'
            );
        }
        return self::$subKeys[$purpose];
    }

    // ------------------------------------------------------- low-level AEAD

    private static function seal(string $plain, string $key, string $aad): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_LEN
        );

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return $iv . $tag . $cipher;
    }

    private static function open(string $blob, string $key, string $aad): string
    {
        if (strlen($blob) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Encrypted value is malformed.');
        }

        $iv = substr($blob, 0, self::IV_LEN);
        $tag = substr($blob, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt(
            $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad
        );

        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered data).');
        }

        return $plain;
    }

    // --------------------------------------------------------- field values

    public static function isEncrypted($value): bool
    {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** Encrypt one value for storage. null stays null. */
    public static function protect(string $column, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (self::isEncrypted($value)) {
            return $value; // never double-encrypt
        }

        $blob = self::seal($value, self::subKey('field'), $column);
        return self::PREFIX . base64_encode($blob);
    }

    /**
     * Decrypt one stored value. Plain (not yet migrated) values pass through
     * unchanged. Throws if the value is encrypted but cannot be opened.
     */
    public static function reveal(string $column, ?string $value): ?string
    {
        if ($value === null || !self::isEncrypted($value)) {
            return $value;
        }

        $blob = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($blob === false) {
            throw new RuntimeException('Encrypted value is malformed.');
        }

        return self::open($blob, self::subKey('field'), $column);
    }

    /** Decrypt every known sensitive column in a result row. */
    public static function decryptRow(array $row): array
    {
        foreach (self::fields() as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && self::isEncrypted($row[$column])) {
                try {
                    $row[$column] = self::reveal($column, $row[$column]);
                } catch (Throwable $e) {
                    // Do not crash the page or leak details; log for the admin.
                    error_log('[FindIT] Could not decrypt column ' . $column . ': ' . $e->getMessage());
                    $row[$column] = '[unreadable]';
                }
            }
        }
        return $row;
    }

    public static function decryptRows(array $rows): array
    {
        return array_map([self::class, 'decryptRow'], $rows);
    }

    // ---------------------------------------------------------- blind index

    /** Deterministic keyed hash used to look up encrypted email / NIM values. */
    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), self::subKey('blind'));
    }

    // ------------------------------------------------------ backup envelope

    /** Encrypt backup data (journal lines and snapshots). Returns base64. */
    public static function sealBackup(string $plain, string $context): string
    {
        return base64_encode(self::seal($plain, self::subKey('backup'), 'backup:' . $context));
    }

    public static function openBackup(string $base64, string $context): string
    {
        $blob = base64_decode(trim($base64), true);
        if ($blob === false) {
            throw new RuntimeException('Backup data is malformed.');
        }
        return self::open($blob, self::subKey('backup'), 'backup:' . $context);
    }
}
