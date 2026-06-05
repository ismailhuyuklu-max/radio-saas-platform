<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * News broadcast slots (every 2h, 08:00–20:00) and "which slot is on air now".
 * Pure + deterministic (timestamp passed in) for testability.
 */
final class BroadcastSlot
{
    public const SLOTS = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];

    /** The most recent slot at or before the given unix timestamp, or null before 08:00. */
    public static function current(int $timestamp): ?string
    {
        $mins = ((int) date('G', $timestamp)) * 60 + ((int) date('i', $timestamp));
        $current = null;
        foreach (self::SLOTS as $slot) {
            [$h, $m] = array_map('intval', explode(':', $slot));
            if ($h * 60 + $m <= $mins) {
                $current = $slot;
            }
        }
        return $current;
    }

    public static function isValid(string $slot): bool
    {
        return in_array($slot, self::SLOTS, true);
    }
}
