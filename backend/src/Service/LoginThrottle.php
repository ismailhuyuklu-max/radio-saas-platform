<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Brute-force login throttle policy (pure decisions; I/O lives in the repo).
 *
 * After MAX_FAILS consecutive failures the account is locked for LOCK_MINUTES.
 */
final class LoginThrottle
{
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;

    public static function isLocked(?string $lockedUntil, int $nowTs): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }
        return strtotime($lockedUntil) > $nowTs;
    }

    public static function shouldLock(int $failCount): bool
    {
        return $failCount >= self::MAX_FAILS;
    }

    /** Seconds remaining on a lock (0 if not locked). */
    public static function retryAfter(?string $lockedUntil, int $nowTs): int
    {
        if (!self::isLocked($lockedUntil, $nowTs)) {
            return 0;
        }
        return max(0, strtotime((string) $lockedUntil) - $nowTs);
    }
}
