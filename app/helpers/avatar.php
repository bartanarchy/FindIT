<?php

/**
 * Student profile picture.
 *
 * A student has either:
 *   - an uploaded photo (stored encrypted in public.user_photo, AES-256-GCM), or
 *   - one of the built-in avatars below (only its key, e.g. "a1", is stored), or
 *   - nothing (a default person icon is shown).
 *
 * Table: public.user_photo (database/migrations/008_user_profile_photo.sql).
 */
final class FiAvatar
{
    public const MAX_BYTES = 2097152; // 2 MB
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Built-in avatars: key => [label, background, skin, hair, shirt]. */
    public const PRESETS = [
        'a1' => ['Sage',   '#E4F0B5', '#F1C9A5', '#3B2A20', '#66794A'],
        'a2' => ['Lilac',  '#E6E8FA', '#E8B896', '#1F1A17', '#54478F'],
        'a3' => ['Clay',   '#F0DDD0', '#D9A67E', '#5A3A24', '#8A3B32'],
        'a4' => ['Sky',    '#D8ECF5', '#F5D3B3', '#8A5A2B', '#2F6C8F'],
        'a5' => ['Sand',   '#F7ECC8', '#C98F66', '#241B16', '#77601F'],
        'a6' => ['Mint',   '#D6EFE2', '#F0C4A0', '#6B3E26', '#2F7A5A'],
        'a7' => ['Rose',   '#F6E0DC', '#E5B28D', '#2B1E18', '#B2546A'],
        'a8' => ['Stone',  '#ECE9DF', '#B98260', '#161212', '#5A6150'],
    ];

    /** Hairstyle per preset, so the avatars do not look identical. */
    private const STYLES = [
        'a1' => 'short', 'a2' => 'long', 'a3' => 'bun', 'a4' => 'short',
        'a5' => 'long', 'a6' => 'bun', 'a7' => 'long', 'a8' => 'short',
    ];

    public static function isPreset(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    // ------------------------------------------------------------ database

    /**
     * Current picture of a student, without the photo bytes.
     * Returns ['type' => 'none'|'preset'|'photo', 'preset' => ?string, 'v' => int].
     */
    public static function info(PDO $pdo, int $userId): array
    {
        $none = ['type' => 'none', 'preset' => null, 'v' => 0];

        try {
            $stmt = $pdo->prepare(
                'SELECT avatar_preset,
                        (foto_data IS NOT NULL) AS has_photo,
                        EXTRACT(EPOCH FROM updated_at)::bigint AS v
                 FROM public.user_photo
                 WHERE id_user = :id'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('[FindIT] Failed to load the profile picture: ' . $e->getMessage());
            return $none;
        }

        if (!$row) {
            return $none;
        }
        if (!empty($row['has_photo'])) {
            return ['type' => 'photo', 'preset' => null, 'v' => (int) $row['v']];
        }
        if (!empty($row['avatar_preset']) && self::isPreset((string) $row['avatar_preset'])) {
            return ['type' => 'preset', 'preset' => (string) $row['avatar_preset'], 'v' => (int) $row['v']];
        }

        return $none;
    }

    /** Decrypted photo for output: ['mime' => ..., 'data' => binary] or null. */
    public static function photo(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT mime, foto_data FROM public.user_photo
             WHERE id_user = :id AND foto_data IS NOT NULL'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!$row || !isset(self::ALLOWED[$row['mime']])) {
            return null;
        }

        $binary = base64_decode((string) Crypto::reveal('foto_data', $row['foto_data']), true);

        return $binary === false ? null : ['mime' => $row['mime'], 'data' => $binary];
    }

    public static function setPreset(PDO $pdo, int $userId, string $key): bool
    {
        if (!self::isPreset($key)) {
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO public.user_photo (id_user, avatar_preset, mime, foto_data, updated_at)
             VALUES (:id, :preset, NULL, NULL, CURRENT_TIMESTAMP)
             ON CONFLICT (id_user) DO UPDATE
                SET avatar_preset = EXCLUDED.avatar_preset,
                    mime = NULL,
                    foto_data = NULL,
                    updated_at = CURRENT_TIMESTAMP'
        );

        return $stmt->execute(['id' => $userId, 'preset' => $key]);
    }

    /**
     * Validate an uploaded file and store it. Returns null on success or an error message.
     * The image is re-encoded with GD, which removes hidden data and metadata (EXIF, scripts).
     */
    public static function savePhoto(PDO $pdo, int $userId, array $file): ?string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'Please choose a photo first.';
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'The photo is too large. The maximum size is 2 MB.';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'The photo could not be uploaded. Please try again.';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'The photo could not be uploaded. Please try again.';
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'The photo is too large. The maximum size is 2 MB.';
        }

        // Check the real content, not the file name or the browser's claim.
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::ALLOWED[$mime])) {
            return 'Only JPG, PNG or WebP photos are allowed.';
        }
        if (($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1 || ($info[0] * $info[1]) > 25000000) {
            return 'This photo has an unsupported size.';
        }

        $binary = self::normalize($tmp, $mime);
        if ($binary === null) {
            return 'This photo could not be processed. Please try another one.';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO public.user_photo (id_user, avatar_preset, mime, foto_data, updated_at)
             VALUES (:id, NULL, :mime, :data, CURRENT_TIMESTAMP)
             ON CONFLICT (id_user) DO UPDATE
                SET avatar_preset = NULL,
                    mime = EXCLUDED.mime,
                    foto_data = EXCLUDED.foto_data,
                    updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'id' => $userId,
            'mime' => 'image/jpeg',
            'data' => Crypto::protect('foto_data', base64_encode($binary)),
        ]);

        return null;
    }

    public static function remove(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('DELETE FROM public.user_photo WHERE id_user = :id');
        $stmt->execute(['id' => $userId]);
    }

    /** Crop to a centered square, shrink to 400x400 and save as JPEG. Null if GD is missing or fails. */
    private static function normalize(string $path, string $mime): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = null;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $source = @imagecreatefromjpeg($path);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $source = @imagecreatefrompng($path);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $source = @imagecreatefromwebp($path);
        }
        if (!$source) {
            return null;
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);
        $size = 400;

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // flatten transparency
        imagecopyresampled($canvas, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);

        ob_start();
        $ok = imagejpeg($canvas, null, 86);
        $out = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return ($ok && is_string($out) && $out !== '') ? $out : null;
    }

    // ------------------------------------------------------------- display

    /** Inline SVG of a built-in avatar. */
    public static function presetSvg(string $key, int $size = 96): string
    {
        if (!self::isPreset($key)) {
            return self::defaultSvg($size);
        }

        [, $bg, $skin, $hair, $shirt] = self::PRESETS[$key];
        $style = self::STYLES[$key] ?? 'short';

        $back = '';
        $front = '';
        if ($style === 'long') {
            $back = '<path d="M26 46c0-17 9-26 24-26s24 9 24 26v22H26z" fill="' . $hair . '"/>';
            $front = '<path d="M31 44c4-10 12-14 19-14s15 4 19 14c-8-4-14-6-19-6s-11 2-19 6z" fill="' . $hair . '"/>';
        } elseif ($style === 'bun') {
            $back = '<circle cx="50" cy="19" r="9" fill="' . $hair . '"/>';
            $front = '<path d="M31 45c1-12 9-17 19-17s18 5 19 17c-6-5-12-7-19-7s-13 2-19 7z" fill="' . $hair . '"/>';
        } else {
            $front = '<path d="M31 46c0-13 8-19 19-19s19 6 19 19c-5-5-11-8-19-8s-14 3-19 8z" fill="' . $hair . '"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 100 100" role="img" aria-hidden="true" focusable="false">'
            . '<rect width="100" height="100" fill="' . $bg . '"/>'
            . $back
            . '<path d="M14 100c0-20 15-30 36-30s36 10 36 30z" fill="' . $shirt . '"/>'
            . '<rect x="43" y="58" width="14" height="14" rx="5" fill="' . $skin . '"/>'
            . '<ellipse cx="50" cy="46" rx="19" ry="21" fill="' . $skin . '"/>'
            . $front
            . '<circle cx="43" cy="48" r="1.8" fill="#2B2B2B"/><circle cx="57" cy="48" r="1.8" fill="#2B2B2B"/>'
            . '<path d="M44 57q6 4 12 0" fill="none" stroke="#2B2B2B" stroke-width="1.6" stroke-linecap="round"/>'
            . '</svg>';
    }

    /** Default person icon (no picture chosen). */
    public static function defaultSvg(int $size = 96): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 100 100" role="img" aria-hidden="true" focusable="false">'
            . '<rect width="100" height="100" fill="#E6E8FA"/>'
            . '<circle cx="50" cy="38" r="15" fill="none" stroke="#54478F" stroke-width="5"/>'
            . '<path d="M22 84c0-17 12-25 28-25s28 8 28 25" fill="none" stroke="#54478F" stroke-width="5" stroke-linecap="round"/>'
            . '</svg>';
    }

    /**
     * Round avatar as HTML. $info comes from FiAvatar::info().
     * $photoUrl is the URL of avatar.php as seen from the current page.
     */
    public static function render(array $info, int $size = 40, string $photoUrl = 'avatar.php', string $extraClass = ''): string
    {
        $class = trim('fi-avatar ' . $extraClass);
        $style = 'width:' . $size . 'px;height:' . $size . 'px;';

        if (($info['type'] ?? 'none') === 'photo') {
            $src = $photoUrl . '?v=' . (int) ($info['v'] ?? 0);
            $inner = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="' . $size
                . '" height="' . $size . '" alt="" loading="lazy">';
        } elseif (($info['type'] ?? 'none') === 'preset') {
            $inner = self::presetSvg((string) $info['preset'], $size);
        } else {
            $inner = self::defaultSvg($size);
        }

        return '<span class="' . $class . '" style="' . $style . '" data-fi-avatar>' . $inner . '</span>';
    }
}
