<?php

declare(strict_types=1);

require __DIR__ . '/../src/Service/TrafficPlanner.php';

use RadioSaaS\Service\TrafficPlanner;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) { $passed++; } else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}

// expandDates
check(TrafficPlanner::expandDates('2026-06-05', 1) === ['2026-06-05'], 'single day');
check(TrafficPlanner::expandDates('2026-06-05', 3) === ['2026-06-05', '2026-06-06', '2026-06-07'], '3 days');
check(count(TrafficPlanner::expandDates('2026-06-05', 999)) === TrafficPlanner::MAX_DAYS, 'days capped at MAX_DAYS');
check(count(TrafficPlanner::expandDates('2026-06-05', 0)) === 1, 'zero -> 1 day');

// buildSpecs cartesian
$targets = [['region' => 'marmara'], ['region' => 'ege']];
$slots = [
    ['slot_time' => '08:00', 'part_code' => 'news', 'content_title' => 'Sabah'],
    ['slot_time' => '12:00', 'part_code' => 'news', 'content_title' => 'Öğle'],
];
$dates = ['2026-06-05', '2026-06-06'];
$specs = TrafficPlanner::buildSpecs($targets, $slots, $dates);
check(count($specs) === 2 * 2 * 2, 'cartesian count = targets*slots*dates (8)');
check($specs[0]['region'] === 'marmara' && $specs[0]['slot_time'] === '08:00' && $specs[0]['plan_date'] === '2026-06-05', 'first spec merged correctly');
check($specs[0]['content_title'] === 'Sabah', 'spec carries slot title');

// merge keeps target + slot + date keys
$st = TrafficPlanner::buildSpecs([['region_id' => 'r1', 'station_id' => 's1']], [['slot_time' => '08:00', 'part_code' => 'news']], ['2026-06-05']);
check($st[0]['station_id'] === 's1' && $st[0]['region_id'] === 'r1' && $st[0]['plan_date'] === '2026-06-05', 'station target merged');

// cap
$many = TrafficPlanner::buildSpecs(
    array_fill(0, 200, ['region' => 'x']),
    array_fill(0, 50, ['slot_time' => '08:00']),
    array_fill(0, 10, '2026-06-05')
);
check(count($many) === TrafficPlanner::MAX_PLANS, 'specs capped at MAX_PLANS');

// estimateCount
check(TrafficPlanner::estimateCount(7, 7, 7) === 343, 'estimate 7x7x7');
check(TrafficPlanner::estimateCount(81, 1, 1) === 81, 'estimate 81 provinces');

echo "TrafficPlanner tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
