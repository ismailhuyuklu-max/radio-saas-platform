<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use DateTimeImmutable;

/**
 * Broadcast-traffic bulk planning expansion.
 *
 * Pure, deterministic cartesian expansion of a plan request:
 *   targets (regions/stations) × slots × dates  →  flat list of plan specs.
 * The controller resolves codes→ids and persists; this only computes the matrix
 * so it can be unit-tested and bounded.
 */
final class TrafficPlanner
{
    public const MAX_DAYS = 31;
    public const MAX_PLANS = 5000;

    /** Inclusive date list starting at $start for $repeatDays days. */
    public static function expandDates(string $start, int $repeatDays): array
    {
        $repeatDays = max(1, min(self::MAX_DAYS, $repeatDays));
        $base = new DateTimeImmutable($start);
        $dates = [];
        for ($i = 0; $i < $repeatDays; $i++) {
            $dates[] = $base->modify("+{$i} day")->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * Cartesian product target × slot × date → plan specs (capped at MAX_PLANS).
     *
     * @param list<array<string,mixed>> $targets each carries region/station keys
     * @param list<array<string,mixed>> $slots   each carries slot_time/part_code/…
     * @param list<string> $dates
     * @return list<array<string,mixed>>
     */
    public static function buildSpecs(array $targets, array $slots, array $dates): array
    {
        $specs = [];
        foreach ($dates as $date) {
            foreach ($targets as $target) {
                foreach ($slots as $slot) {
                    if (count($specs) >= self::MAX_PLANS) {
                        return $specs;
                    }
                    $specs[] = array_merge($target, $slot, ['plan_date' => $date]);
                }
            }
        }
        return $specs;
    }

    /** Predicted total before persisting (for the UI preview). */
    public static function estimateCount(int $targets, int $slots, int $days): int
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        return $targets * $slots * $days;
    }
}
