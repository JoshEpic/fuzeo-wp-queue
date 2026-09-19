<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

final class TraceSanitizer
{
    public const MAX_FRAMES = 8;

    public const MAX_BYTES = 4000;

    public static function sanitize(\Throwable $throwable): string
    {
        $frames = [];
        $trace = $throwable->getTrace();
        $limit = min(self::MAX_FRAMES, count($trace));
        for ($i = 0; $i < $limit; $i++) {
            $frame = $trace[$i];
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '{unknown}';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $call = is_string($class) && $class !== ''
                ? $class . $type . $function
                : (is_string($function) ? $function : '{unknown}');
            $base = is_string($file) && $file !== '' ? basename($file) : '[internal]';
            $frames[] = $call . ' ' . $base . ':' . (is_int($line) ? $line : 0);
        }

        $header = $throwable::class . ' at ' . basename($throwable->getFile()) . ':' . $throwable->getLine();
        $body = $header . "\n" . implode("\n", $frames);
        if (strlen($body) > self::MAX_BYTES) {
            return substr($body, 0, self::MAX_BYTES - 3) . '...';
        }

        return $body;
    }
}
