<?php

declare(strict_types=1);

/**
 * Standalone RevenueService test (no PHPUnit).
 * Run: php backend/tests/RevenueTest.php
 */

require __DIR__ . '/../src/Service/RevenueService.php';

use RadioSaaS\Service\RevenueService;

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$msg}\n");
    }
}
function near(float $a, float $b, float $eps = 0.01): bool
{
    return abs($a - $b) <= $eps;
}

// reachSum
check(RevenueService::reachSum(['marmara']) === 850_000, 'reachSum single region');
check(RevenueService::reachSum(['marmara', 'ege']) === 1_270_000, 'reachSum two regions');
check(RevenueService::reachSum(['bogus']) === 0, 'reachSum ignores unknown region');

// day math
check(RevenueService::totalDays('2026-06-01', '2026-06-10') === 10, 'totalDays inclusive');
check(RevenueService::totalDays('2026-06-10', '2026-06-01') === 0, 'totalDays end<start = 0');
check(RevenueService::deliveredDays('2026-06-01', '2026-06-10', '2026-06-05') === 5, 'deliveredDays mid-run');
check(RevenueService::deliveredDays('2026-06-01', '2026-06-10', '2026-05-30') === 0, 'deliveredDays before start');
check(RevenueService::deliveredDays('2026-06-01', '2026-06-10', '2026-06-30') === 10, 'deliveredDays after end caps at total');

// CPM campaign
$cpm = RevenueService::computeCampaign([
    'pricing_model' => 'cpm',
    'rate' => 50,
    'budget' => 1_000_000,
    'spots_per_day' => 2,
    'target_regions' => ['marmara'],
    'starts_at' => '2026-06-01',
    'ends_at' => '2026-06-10',
    'status' => 'active',
], '2026-06-05');
check($cpm['projected_impressions'] === 17_000_000, 'cpm projected impressions');
check(near($cpm['projected_revenue'], 850_000.0), 'cpm projected revenue');
check($cpm['delivered_impressions'] === 8_500_000, 'cpm delivered impressions (day 5)');
check(near($cpm['delivered_revenue'], 425_000.0), 'cpm delivered revenue');

// CPP campaign
$cpp = RevenueService::computeCampaign([
    'pricing_model' => 'cpp',
    'rate' => 20,
    'budget' => 0,
    'spots_per_day' => 3,
    'target_regions' => ['marmara', 'ege'],
    'starts_at' => '2026-06-01',
    'ends_at' => '2026-06-04',
    'status' => 'active',
], '2026-06-30');
check($cpp['projected_spots'] === 24, 'cpp projected spots (3*2*4)');
check(near($cpp['projected_revenue'], 480.0), 'cpp projected revenue');

// flat campaign — pro-rata delivered
$flat = RevenueService::computeCampaign([
    'pricing_model' => 'flat',
    'rate' => 0,
    'budget' => 1000,
    'spots_per_day' => 1,
    'target_regions' => ['marmara'],
    'starts_at' => '2026-06-01',
    'ends_at' => '2026-06-10',
    'status' => 'active',
], '2026-06-05');
check(near($flat['projected_revenue'], 1000.0), 'flat projected = budget');
check(near($flat['delivered_revenue'], 500.0), 'flat delivered pro-rata (5/10)');

// actuals override the time-based delivered figures
$withActuals = RevenueService::computeCampaign([
    'pricing_model' => 'cpm',
    'rate' => 50,
    'budget' => 1_000_000,
    'spots_per_day' => 2,
    'target_regions' => ['marmara'],
    'starts_at' => '2026-06-01',
    'ends_at' => '2026-06-10',
    'status' => 'active',
], '2026-06-05', ['spots' => 10, 'impressions' => 2_000_000]);
check($withActuals['has_actuals'] === true, 'has_actuals true when airings present');
check($withActuals['delivered_impressions'] === 2_000_000, 'delivered impressions from actual airings');
check(near($withActuals['delivered_revenue'], 100_000.0), 'delivered revenue from actual (2M/1000*50)');
check($withActuals['projected_revenue'] === $cpm['projected_revenue'], 'projection unchanged by actuals');

$noActuals = RevenueService::computeCampaign([
    'pricing_model' => 'cpm', 'rate' => 50, 'budget' => 0, 'spots_per_day' => 2,
    'target_regions' => ['marmara'], 'starts_at' => '2026-06-01', 'ends_at' => '2026-06-10', 'status' => 'active',
], '2026-06-05', ['spots' => 0, 'impressions' => 0]);
check($noActuals['has_actuals'] === false, 'has_actuals false when zero airings (falls back to estimate)');

// summary
$summary = RevenueService::summary([
    [
        'pricing_model' => 'cpm', 'rate' => 50, 'budget' => 1_000_000, 'spots_per_day' => 2,
        'target_regions' => ['marmara'], 'starts_at' => '2026-06-01', 'ends_at' => '2026-06-10', 'status' => 'active',
    ],
    [
        'pricing_model' => 'cpp', 'rate' => 20, 'budget' => 0, 'spots_per_day' => 3,
        'target_regions' => ['marmara', 'ege'], 'starts_at' => '2026-06-01', 'ends_at' => '2026-06-04', 'status' => 'paused',
    ],
], '2026-06-05');
check($summary['campaign_count'] === 2, 'summary counts campaigns');
check($summary['active_campaigns'] === 1, 'summary counts active');
check($summary['total_projected_revenue'] > 0, 'summary total projected > 0');
// Effective CPM = delivered revenue / delivered impressions × 1000, blended
// across models. The CPP campaign adds cheap impressions, diluting CPM below
// the pure-CPM rate of 50 but keeping it positive.
check(
    $summary['avg_cpm'] > 0 && $summary['avg_cpm'] < 50.0,
    'summary avg_cpm is a positive blended value below the CPM rate (got ' . $summary['avg_cpm'] . ')'
);
check(isset($summary['revenue_by_region']['marmara']), 'summary has region breakdown');
check(array_key_exists('cpm', $summary['revenue_by_model']), 'summary has model breakdown');

echo "Revenue tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
