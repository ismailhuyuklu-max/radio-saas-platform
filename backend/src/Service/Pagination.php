<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Clamps user-supplied limit/offset to safe bounds so list endpoints can never
 * be coerced into returning the whole table (memory / DoS protection).
 */
final class Pagination
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 1000;

    /**
     * @return array{0:int,1:int} [limit, offset]
     */
    public static function clamp(mixed $limit, mixed $offset): array
    {
        $l = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
        if ($l <= 0) {
            $l = self::DEFAULT_LIMIT;
        }
        $l = min($l, self::MAX_LIMIT);

        $o = is_numeric($offset) ? (int) $offset : 0;
        if ($o < 0) {
            $o = 0;
        }

        return [$l, $o];
    }
}
