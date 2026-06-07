<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Clamps user-supplied limit/offset to safe bounds so list endpoints can never
 * be coerced into returning the whole table (memory / DoS protection).
 */
final class Pagination
{
    // Faz CTO-18: DEFAULT 500→100 — 500 row payload P95 2.3s'e çıkıyordu.
    // 100 row ~50KB JSON, P95 hedef ~500ms. Explicit ?limit ile override.
    public const DEFAULT_LIMIT = 100;
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
