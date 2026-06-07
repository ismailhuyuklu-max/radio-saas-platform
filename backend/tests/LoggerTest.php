<?php

declare(strict_types=1);

/**
 * Faz H4-1 — Logger unit tests.
 *
 * Run:  php backend/tests/LoggerTest.php
 */

require __DIR__ . '/../src/Service/Logger.php';

use RadioSaaS\Service\Logger;

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

/**
 * Logger::log → error_log → stderr. Test izolasyonu için
 * ini_set ile geçici dosyaya yönlendir.
 */
function captureLog(callable $fn): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'logger_test_');
    $prev = ini_get('error_log');
    ini_set('error_log', $tmp);
    try {
        $fn();
    } finally {
        ini_set('error_log', $prev !== false ? $prev : '');
    }
    $content = file_get_contents($tmp) ?: '';
    @unlink($tmp);
    return $content;
}

// --------------------------------------------------------------------
// init() — random + idempotent
// --------------------------------------------------------------------
Logger::resetForTest();
$rid = Logger::init([]);
check(preg_match('/^[a-f0-9]{32}$/', $rid) === 1, 'init() bare server → 32-char hex request id');
check(Logger::init([]) === $rid, 'init() idempotent — aynı id döner');

// --------------------------------------------------------------------
// init() — incoming X-Request-Id whitelist
// --------------------------------------------------------------------
Logger::resetForTest();
$ridIncoming = Logger::init(['HTTP_X_REQUEST_ID' => 'abc-DEF_123']);
check($ridIncoming === 'abc-DEF_123', 'valid X-Request-Id kabul edildi (downstream tracing)');

Logger::resetForTest();
$ridSpoof = Logger::init(['HTTP_X_REQUEST_ID' => '<script>alert(1)</script>']);
check(
    $ridSpoof !== '<script>alert(1)</script>' && preg_match('/^[a-f0-9]{32}$/', $ridSpoof) === 1,
    'kötü niyetli X-Request-Id reddedildi, random fallback'
);

Logger::resetForTest();
$ridShort = Logger::init(['HTTP_X_REQUEST_ID' => 'short']);
check($ridShort !== 'short', '8 char altı X-Request-Id reddedildi');

// --------------------------------------------------------------------
// log() — JSON shape
// --------------------------------------------------------------------
Logger::resetForTest();
Logger::init(['HTTP_X_REQUEST_ID' => 'test-corrid-001']);
$captured = captureLog(static fn () => Logger::error('hello world', ['path' => '/x', 'status' => 500]));
$lines = array_values(array_filter(explode("\n", $captured), static fn ($l) => trim($l) !== ''));
check(count($lines) === 1, 'tek log satırı yazıldı');
// error_log mantıken her satıra timestamp prefix ekleyebilir; gerçek JSON'u son satırda ara.
$jsonStart = strpos($lines[0] ?? '', '{');
$json = $jsonStart === false ? '' : substr($lines[0], $jsonStart);
$decoded = json_decode($json, true);
check(is_array($decoded), 'log satırı JSON parse edilebilir');
check(($decoded['level'] ?? '') === 'error', 'level === error');
check(($decoded['msg'] ?? '') === 'hello world', 'msg geçirilen değer');
check(($decoded['request_id'] ?? '') === 'test-corrid-001', 'request_id satıra dahil');
check(($decoded['path'] ?? '') === '/x', 'context fields merge edilir');
check(($decoded['status'] ?? 0) === 500, 'context status int alanı korundu');
check(isset($decoded['ts']), 'ts alanı dolu');

// --------------------------------------------------------------------
// context override koruması
// --------------------------------------------------------------------
Logger::resetForTest();
Logger::init([]);
$captured = captureLog(static fn () => Logger::info('msg', ['level' => 'critical', 'msg' => 'malicious']));
$j = json_decode(substr($captured, (int) strpos($captured, '{')), true) ?: [];
check(($j['level'] ?? '') === 'info', 'reserved level alanı context tarafından override edilemez');
check(($j['msg'] ?? '') === 'msg', 'reserved msg alanı context tarafından override edilemez');

// --------------------------------------------------------------------
// elapsedMs() — pozitif int
// --------------------------------------------------------------------
Logger::resetForTest();
Logger::init([]);
usleep(2000); // 2 ms
$ms = Logger::elapsedMs();
check($ms >= 1, 'elapsedMs() >= 1 ms (after usleep 2 ms)');

echo "Logger tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
