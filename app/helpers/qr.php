<?php

declare(strict_types=1);

/**
 * Minimal QR code generator (byte mode, error correction level M, versions 1-10).
 *
 * Turns a short text (the pickup token) into a PNG image on the server, so the QR code
 * needs no JavaScript, no extra static files and no PHP image extension.
 * The algorithm follows the QR Code Model 2 specification (ISO/IEC 18004).
 */
final class FiQr
{
    // Error correction level M: codewords per block and number of blocks, index = version.
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const NUM_BLOCKS    = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const MAX_VERSION   = 10;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];
    private int $size;

    /** Return a PNG image (binary string) of the QR code for $text. */
    public static function png(string $text, int $scale = 8, int $border = 4): string
    {
        $matrix = (new self($text))->modules;
        $n = count($matrix);
        $dim = ($n + $border * 2) * $scale;

        $rowBytes = intdiv($dim + 7, 8);
        $raw = '';
        for ($py = 0; $py < $dim; $py++) {
            $my = intdiv($py, $scale) - $border;
            $row = array_fill(0, $rowBytes, 0xFF); // 1 bit = white
            if ($my >= 0 && $my < $n) {
                for ($px = 0; $px < $dim; $px++) {
                    $mx = intdiv($px, $scale) - $border;
                    if ($mx >= 0 && $mx < $n && $matrix[$my][$mx]) {
                        $row[$px >> 3] &= ~(0x80 >> ($px & 7)); // 0 bit = black
                    }
                }
            }
            $raw .= "\x00" . pack('C*', ...$row);
        }

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $dim, $dim, 1, 0, 0, 0, 0))
            . $chunk('IDAT', self::zlib($raw))
            . $chunk('IEND', '');
    }

    /** data: URI for use directly in an <img src> or a download link. */
    public static function pngDataUri(string $text): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($text));
    }

    private static function zlib(string $raw): string
    {
        if (function_exists('gzcompress')) {
            $z = gzcompress($raw, 9);
            if ($z !== false) {
                return $z;
            }
        }

        // Fallback without the zlib extension: "stored" (uncompressed) deflate blocks.
        $out = "\x78\x01";
        $len = strlen($raw);
        $pos = 0;
        do {
            $part = substr($raw, $pos, 65535);
            $pos += strlen($part);
            $final = $pos >= $len ? 1 : 0;
            $out .= chr($final) . pack('v', strlen($part)) . pack('v', (~strlen($part)) & 0xFFFF) . $part;
        } while ($pos < $len);

        $a = 1;
        $b = 0;
        for ($i = 0; $i < $len; $i++) {
            $a = ($a + ord($raw[$i])) % 65521;
            $b = ($b + $a) % 65521;
        }

        return $out . pack('N', ($b << 16) | $a);
    }

    private function __construct(string $text)
    {
        $data = array_values(unpack('C*', $text) ?: []);
        $version = 0;

        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + count($data) * 8 <= self::dataCodewords($v) * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new RuntimeException('Text is too long for the QR code.');
        }

        // Build the bit stream: mode, length, data, terminator, padding.
        $bits = [];
        $put = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put(count($data), $version <= 9 ? 8 : 16);
        foreach ($data as $byte) {
            $put($byte, 8);
        }
        $capacityBits = self::dataCodewords($version) * 8;
        $put(0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $put($pad, 8);
        }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $byteBits) {
            $codewords[] = bindec(implode('', $byteBits));
        }

        $all = $this->addEccAndInterleave($codewords, $version);

        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        $this->drawFunctionPatterns($version);
        $this->drawCodewords($all);

        // Pick the mask with the lowest penalty score.
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
            $this->applyMask($mask); // undo (XOR)
        }
        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
    }

    private static function rawModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function dataCodewords(int $ver): int
    {
        return intdiv(self::rawModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    /** @param int[] $data @return int[] */
    private function addEccAndInterleave(array $data, int $ver): array
    {
        $numBlocks = self::NUM_BLOCKS[$ver];
        $eccLen = self::ECC_PER_BLOCK[$ver];
        $rawCodewords = intdiv(self::rawModules($ver), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0; // placeholder so all blocks have equal length
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /** @return int[] */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    /** @param int[] $data @param int[] $divisor @return int[] */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $ver): void
    {
        $size = $this->size;

        for ($i = 0; $i < $size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $xx = $cx + $dx;
                    $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size) {
                        $this->set($xx, $yy, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }

        $pos = $this->alignmentPositions($ver);
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->drawFormatBits(0); // reserve the format area
        $this->drawVersion($ver);
    }

    /** @return int[] */
    private function alignmentPositions(int $ver): array
    {
        if ($ver === 1) {
            return [];
        }
        $numAlign = intdiv($ver, 7) + 2;
        $step = intdiv($ver * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [6];
        for ($i = 0, $pos = $this->size - 7; $i < $numAlign - 1; $i++, $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // 0 = error correction level M
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) !== 0;
        $size = $this->size;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $size - 15 + $i, $bit($i));
        }
        $this->set(8, $size - 8, true);
    }

    private function drawVersion(int $ver): void
    {
        if ($ver < 7) {
            return;
        }
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $dark);
            $this->set($b, $a, $dark);
        }
    }

    /** @param int[] $data */
    private function drawCodewords(array $data): void
    {
        $size = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty score (lower is better); only used to choose between the 8 masks. */
    private function penalty(): int
    {
        $size = $this->size;
        $m = $this->modules;
        $score = 0;

        // Runs of 5+ same-colored modules in rows and columns.
        for ($a = 0; $a < $size; $a++) {
            foreach ([0, 1] as $dir) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $cur = $dir === 0 ? $m[$a][$b] : $m[$b][$a];
                    $prev = $dir === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
        }

        // 2x2 blocks of the same color.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Balance of dark and light modules.
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                $dark += $cell ? 1 : 0;
            }
        }
        $k = intdiv(abs($dark * 20 - $size * $size * 10) + $size * $size - 1, $size * $size) - 1;
        $score += max(0, $k) * 10;

        return $score;
    }
}
