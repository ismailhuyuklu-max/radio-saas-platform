<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * System metric parsing (CPU / RAM / Disk).
 *
 * The /proc parsing and percentage math are pure functions so they can be
 * unit-tested with sample input; the controller supplies the live readings.
 */
final class MetricsService
{
    /**
     * Parse /proc/meminfo contents into memory usage figures (kB + percent).
     *
     * @return array{total_kb:int, available_kb:int, used_kb:int, used_pct:float}
     */
    public static function parseMeminfo(string $contents): array
    {
        $total = self::grepKb($contents, 'MemTotal');
        $available = self::grepKb($contents, 'MemAvailable');
        if ($available === 0 && $total > 0) {
            // Older kernels: approximate available = free + buffers + cached.
            $available = self::grepKb($contents, 'MemFree')
                + self::grepKb($contents, 'Buffers')
                + self::grepKb($contents, 'Cached');
        }
        $used = max(0, $total - $available);
        $usedPct = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;

        return [
            'total_kb' => $total,
            'available_kb' => $available,
            'used_kb' => $used,
            'used_pct' => $usedPct,
        ];
    }

    private static function grepKb(string $contents, string $key): int
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB/mi', $contents, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Parse the aggregate "cpu" line of /proc/stat into idle + total jiffies.
     *
     * @return array{idle:int, total:int}
     */
    public static function parseCpuStat(string $statLine): array
    {
        // Format: "cpu  user nice system idle iowait irq softirq steal guest guest_nice"
        if (!preg_match('/^cpu\s+(.+)$/m', $statLine, $m)) {
            return ['idle' => 0, 'total' => 0];
        }
        $parts = array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []);
        $idle = ($parts[3] ?? 0) + ($parts[4] ?? 0); // idle + iowait
        $total = array_sum($parts);
        return ['idle' => $idle, 'total' => $total];
    }

    /**
     * CPU usage percent between two /proc/stat samples.
     *
     * @param array{idle:int, total:int} $prev
     * @param array{idle:int, total:int} $curr
     */
    public static function cpuUsagePct(array $prev, array $curr): float
    {
        $totalDelta = $curr['total'] - $prev['total'];
        $idleDelta = $curr['idle'] - $prev['idle'];
        if ($totalDelta <= 0) {
            return 0.0;
        }
        $usage = (($totalDelta - $idleDelta) / $totalDelta) * 100;
        return round(max(0.0, min(100.0, $usage)), 1);
    }

    /**
     * @return array{total_bytes:float, free_bytes:float, used_bytes:float, used_pct:float}
     */
    public static function diskUsage(float $freeBytes, float $totalBytes): array
    {
        $used = max(0.0, $totalBytes - $freeBytes);
        $usedPct = $totalBytes > 0 ? round(($used / $totalBytes) * 100, 1) : 0.0;
        return [
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'used_bytes' => $used,
            'used_pct' => $usedPct,
        ];
    }

    /** Map a usage percent to a health tone. */
    public static function tone(float $pct): string
    {
        if ($pct >= 90) {
            return 'critical';
        }
        if ($pct >= 75) {
            return 'warning';
        }
        return 'ok';
    }
}
