<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Service\SmartPlacement;

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$msg}\n");
    }
}

// Rule 1 — sponsor after news.
$r = SmartPlacement::suggest([
    ['slot_time' => '08:00', 'part_code' => 'news'],
]);
$sponsor = array_values(array_filter($r['suggestions'], fn ($s) => $s['part_code'] === 'sponsor'));
check(count($sponsor) === 1 && $sponsor[0]['slot_time'] === '08:00', 'news slot gets a sponsor suggestion');

// News slot that already has a sponsor → no duplicate sponsor suggestion.
$r = SmartPlacement::suggest([
    ['slot_time' => '08:00', 'part_code' => 'news'],
    ['slot_time' => '08:00', 'part_code' => 'sponsor'],
]);
$sponsor = array_filter($r['suggestions'], fn ($s) => $s['slot_time'] === '08:00' && $s['part_code'] === 'sponsor');
check(count($sponsor) === 0, 'no duplicate sponsor when one already exists');

// Rule 2 — fill prime gaps. With only 08:00 filled, 12:00 and 18:00 should be suggested.
$r = SmartPlacement::suggest([
    ['slot_time' => '08:00', 'part_code' => 'news'],
]);
$primeNews = array_values(array_filter(
    $r['suggestions'],
    fn ($s) => $s['part_code'] === 'news' && in_array($s['slot_time'], ['12:00', '18:00'], true)
));
check(count($primeNews) === 2, 'empty prime slots (12:00,18:00) suggested as news');

// Rule 3 — adjacent ad warning (10:00 & 12:00 are adjacent day slots).
$r = SmartPlacement::suggest([
    ['slot_time' => '10:00', 'part_code' => 'ad'],
    ['slot_time' => '12:00', 'part_code' => 'ad'],
]);
check(count($r['warnings']) >= 1, 'adjacent ads raise a spacing warning');

// Non-adjacent ads (08:00 & 14:00) → no spacing warning.
$r = SmartPlacement::suggest([
    ['slot_time' => '08:00', 'part_code' => 'ad'],
    ['slot_time' => '14:00', 'part_code' => 'ad'],
]);
$spacing = array_filter($r['warnings'], fn ($w) => str_contains($w['message'], 'art arda'));
check(count($spacing) === 0, 'non-adjacent ads raise no spacing warning');

// Rule 4 — daily ad cap.
$many = [];
foreach (['08:00', '10:00', '12:00', '14:00'] as $s) {
    $many[] = ['slot_time' => $s, 'part_code' => 'ad'];
}
$r = SmartPlacement::suggest($many, ['max_ads' => 2]);
$cap = array_filter($r['warnings'], fn ($w) => str_contains($w['message'], 'sınırı'));
check(count($cap) === 1, 'exceeding the ad cap raises a warning');

echo "\nSmartPlacement: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
