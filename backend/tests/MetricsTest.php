<?php

declare(strict_types=1);

/** MetricsService parsing tests. Run: php backend/tests/MetricsTest.php */

require __DIR__ . '/../src/Service/MetricsService.php';

use RadioSaaS\Service\MetricsService;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$m}\n");
    }
}

$meminfo = "MemTotal:       16384000 kB\nMemFree:         2000000 kB\nMemAvailable:    8192000 kB\nBuffers:          100000 kB\n";
$mem = MetricsService::parseMeminfo($meminfo);
check($mem['total_kb'] === 16384000, 'meminfo total');
check($mem['available_kb'] === 8192000, 'meminfo available');
check($mem['used_kb'] === 8192000, 'meminfo used = total-available');
check($mem['used_pct'] === 50.0, 'meminfo used pct');

// older kernel without MemAvailable
$old = "MemTotal:       1000 kB\nMemFree:         200 kB\nBuffers:          100 kB\nCached:           200 kB\n";
$memOld = MetricsService::parseMeminfo($old);
check($memOld['available_kb'] === 500, 'meminfo fallback available = free+buffers+cached');
check($memOld['used_pct'] === 50.0, 'meminfo fallback used pct');

// cpu stat
$stat = "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 ...";
$c1 = MetricsService::parseCpuStat($stat);
check($c1['idle'] === 800, 'cpu idle = idle+iowait (700+100)');
check($c1['total'] === 1000, 'cpu total = sum (100+100+700+100)');

$prev = ['idle' => 800, 'total' => 1100];
$curr = ['idle' => 850, 'total' => 1200]; // 100 total delta, 50 idle delta → 50% busy
check(MetricsService::cpuUsagePct($prev, $curr) === 50.0, 'cpu usage pct');
check(MetricsService::cpuUsagePct($prev, $prev) === 0.0, 'cpu usage no delta = 0');

// disk
$disk = MetricsService::diskUsage(25.0, 100.0);
check($disk['used_bytes'] === 75.0, 'disk used bytes');
check($disk['used_pct'] === 75.0, 'disk used pct');

// tone thresholds
check(MetricsService::tone(50) === 'ok', 'tone ok');
check(MetricsService::tone(80) === 'warning', 'tone warning');
check(MetricsService::tone(95) === 'critical', 'tone critical');

echo "Metrics tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
