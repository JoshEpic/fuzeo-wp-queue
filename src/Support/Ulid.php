<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

/**
 * Crockford Base32 ULID (26 characters). Time-sortable, 128-bit, JSON-safe.
 *
 * Chosen over UUID v4 because queue rows are scanned by insertion time, and
 * over UUID v7 to avoid a dependency while keeping a compact public ID.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $milliseconds = null): string
    {
        $ms = $milliseconds ?? (int) floor(microtime(true) * 1000);
        if ($ms < 0) {
            throw new \InvalidArgumentException('ULID timestamp cannot be negative.');
        }

        $time = self::encodeTime($ms);
        $random = self::encodeRandom(random_bytes(10));

        return $time . $random;
    }

    /**
     * Stable ULID for orchestration identities (chain step, batch member, follow-up).
     */
    public static function fromMaterial(string $material, int $milliseconds): string
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('ULID timestamp cannot be negative.');
        }
        $hash = hash('sha256', $material, true);

        return self::encodeTime($milliseconds) . self::encodeRandom(substr($hash, 0, 10));
    }

    public static function isValid(string $value): bool
    {
        if (strlen($value) !== 26) {
            return false;
        }

        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value);
    }

    private static function encodeTime(int $ms): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $mod = $ms % 32;
            $chars = self::ALPHABET[$mod] . $chars;
            $ms = intdiv($ms, 32);
        }

        return $chars;
    }

    private static function encodeRandom(string $bytes): string
    {
        $chars = '';
        $keep = 0;
        $bits = 0;

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $keep = ($keep << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $chars .= self::ALPHABET[($keep >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            $chars .= self::ALPHABET[($keep << (5 - $bits)) & 31];
        }

        return substr($chars, 0, 16);
    }
}
