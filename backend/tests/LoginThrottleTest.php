<?php

declare(strict_types=1);

require __DIR__ . '/../src/Service/LoginThrottle.php';

use RadioSaaS\Service\LoginThrottle;

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

$now = strtotime('2026-06-05 12:00:00');

check(LoginThrottle::isLocked(null, $now) === false, 'null lock = not locked');
check(LoginThrottle::isLocked('2026-06-05 12:05:00', $now) === true, 'future lock = locked');
check(LoginThrottle::isLocked('2026-06-05 11:55:00', $now) === false, 'past lock = not locked');

check(LoginThrottle::shouldLock(4) === false, '4 fails = not yet locked');
check(LoginThrottle::shouldLock(5) === true, '5 fails = lock');
check(LoginThrottle::shouldLock(6) === true, '6 fails = lock');

check(LoginThrottle::retryAfter('2026-06-05 12:05:00', $now) === 300, 'retryAfter = 300s');
check(LoginThrottle::retryAfter(null, $now) === 0, 'retryAfter null = 0');
check(LoginThrottle::retryAfter('2026-06-05 11:00:00', $now) === 0, 'retryAfter past = 0');

check(LoginThrottle::MAX_FAILS === 5, 'MAX_FAILS constant');
check(LoginThrottle::LOCK_MINUTES === 15, 'LOCK_MINUTES constant');

echo "LoginThrottle tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
