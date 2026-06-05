<?php

declare(strict_types=1);

require __DIR__ . '/../src/Service/BroadcastSlot.php';

use RadioSaaS\Service\BroadcastSlot;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) { $passed++; } else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}

$at = static fn (string $hm): int => strtotime('2026-06-05 ' . $hm);

check(BroadcastSlot::current($at('06:00')) === null, 'before 08:00 -> null');
check(BroadcastSlot::current($at('08:00')) === '08:00', 'at 08:00 -> 08:00');
check(BroadcastSlot::current($at('09:30')) === '08:00', '09:30 -> 08:00');
check(BroadcastSlot::current($at('18:45')) === '18:00', '18:45 -> 18:00');
check(BroadcastSlot::current($at('20:00')) === '20:00', '20:00 -> 20:00');
check(BroadcastSlot::current($at('23:59')) === '20:00', 'after 20:00 -> 20:00');

check(BroadcastSlot::isValid('10:00') === true, 'valid slot');
check(BroadcastSlot::isValid('09:00') === false, 'invalid slot');
check(count(BroadcastSlot::SLOTS) === 7, '7 slots');

echo "BroadcastSlot tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
