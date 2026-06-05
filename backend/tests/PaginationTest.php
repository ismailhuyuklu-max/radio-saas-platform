<?php

declare(strict_types=1);

require __DIR__ . '/../src/Service/Pagination.php';

use RadioSaaS\Service\Pagination;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) { $passed++; } else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}

check(Pagination::clamp(50, 10) === [50, 10], 'valid passthrough');
check(Pagination::clamp(null, null) === [Pagination::DEFAULT_LIMIT, 0], 'defaults');
check(Pagination::clamp(99999, 0) === [Pagination::MAX_LIMIT, 0], 'limit capped at MAX');
check(Pagination::clamp(0, 0) === [Pagination::DEFAULT_LIMIT, 0], 'zero limit -> default');
check(Pagination::clamp(-5, -5) === [Pagination::DEFAULT_LIMIT, 0], 'negatives sanitized');
check(Pagination::clamp('20', '40') === [20, 40], 'numeric strings parsed');
check(Pagination::clamp('abc', 'xyz') === [Pagination::DEFAULT_LIMIT, 0], 'non-numeric -> default');

echo "Pagination tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
