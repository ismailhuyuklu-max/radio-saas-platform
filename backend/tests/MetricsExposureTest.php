<?php

declare(strict_types=1);

/**
 * Faz H5-1 — Metrics service unit tests.
 * Run: php backend/tests/MetricsExposureTest.php
 */

require __DIR__ . '/../src/Service/Metrics.php';

use RadioSaaS\Service\Metrics;

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

// -- gauge --
Metrics::resetForTest();
Metrics::register('test_gauge', 'gauge', 'Test gauge help');
Metrics::gauge('test_gauge', 42);
$out = Metrics::render();
check(str_contains($out, '# HELP test_gauge Test gauge help'), 'HELP satırı render edildi');
check(str_contains($out, '# TYPE test_gauge gauge'), 'TYPE satırı render edildi');
check(str_contains($out, 'test_gauge 42'), 'gauge değeri 42 render edildi');

// -- gauge with labels --
Metrics::resetForTest();
Metrics::gauge('queue_depth', 7, ['status' => 'pending']);
Metrics::gauge('queue_depth', 2, ['status' => 'failed']);
$out = Metrics::render();
check(str_contains($out, 'queue_depth{status="pending"} 7'), 'label li sample 1');
check(str_contains($out, 'queue_depth{status="failed"} 2'), 'label li sample 2');

// -- counter aggregates --
Metrics::resetForTest();
Metrics::counter('http_requests_total', 1, ['method' => 'GET']);
Metrics::counter('http_requests_total', 1, ['method' => 'GET']);
Metrics::counter('http_requests_total', 1, ['method' => 'POST']);
$out = Metrics::render();
check(str_contains($out, 'http_requests_total{method="GET"} 2'), 'counter GET aggregated to 2');
check(str_contains($out, 'http_requests_total{method="POST"} 1'), 'counter POST = 1');

// -- escape special chars --
Metrics::resetForTest();
Metrics::gauge('escape_test', 1, ['msg' => 'line1' . "\n" . 'line2"with quote']);
$out = Metrics::render();
check(str_contains($out, '\\n'), 'newline escape edildi');
check(str_contains($out, '\\"'), 'quote escape edildi');

// -- float formatting --
Metrics::resetForTest();
Metrics::gauge('duration', 1.23456);
$out = Metrics::render();
check(str_contains($out, 'duration 1.23456'), 'float değer 6 hane');

Metrics::resetForTest();
Metrics::gauge('round_one', 1.0);
$out = Metrics::render();
check(str_contains($out, 'round_one 1'), 'tam sayı float trailing zero trim');

// -- idempotent register --
Metrics::resetForTest();
Metrics::register('m', 'gauge', 'first');
Metrics::register('m', 'gauge', 'overridden'); // no-op
$out = Metrics::render();
check(str_contains($out, '# HELP m first'), 'register idempotent');

echo "Metrics tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
