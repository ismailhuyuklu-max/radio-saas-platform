<?php

declare(strict_types=1);

/**
 * Faz H3-4 — RequestContext (trusted proxy) policy testleri.
 *
 * Run:  php backend/tests/RequestContextTest.php
 * Exits non-zero if any assertion fails.
 */

require __DIR__ . '/../src/Service/RequestContext.php';

use RadioSaaS\Service\RequestContext;

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

// --------------------------------------------------------------------
// trustedProxies parse / normalize
// --------------------------------------------------------------------
check(RequestContext::trustedProxies('') === [], 'empty env → no trusted proxies');
check(RequestContext::trustedProxies('10.0.0.1') === ['10.0.0.1'], 'single trusted proxy parses');
check(
    RequestContext::trustedProxies('10.0.0.1, 10.0.0.2,bogus,  10.0.0.3') === ['10.0.0.1', '10.0.0.2', '10.0.0.3'],
    'whitespace + invalid filtered out'
);
check(RequestContext::trustedProxies('not-an-ip') === [], 'non-IP value rejected');

// --------------------------------------------------------------------
// clientIp — untrusted upstream
// --------------------------------------------------------------------
$server = [
    'REMOTE_ADDR' => '203.0.113.5',
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
];
check(
    RequestContext::clientIp($server, '') === '203.0.113.5',
    'no trusted proxies → XFF ignored (spoof korunması)'
);
check(
    RequestContext::clientIp($server, '10.0.0.1') === '203.0.113.5',
    'remote not in trusted list → XFF ignored'
);

// --------------------------------------------------------------------
// clientIp — trusted upstream
// --------------------------------------------------------------------
$serverTrusted = [
    'REMOTE_ADDR' => '10.0.0.5',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 10.0.0.5',
];
check(
    RequestContext::clientIp($serverTrusted, '10.0.0.5') === '198.51.100.10',
    'trusted proxy → ilk XFF hop alınır'
);

$serverRealIp = [
    'REMOTE_ADDR' => '10.0.0.5',
    'HTTP_X_REAL_IP' => '198.51.100.20',
];
check(
    RequestContext::clientIp($serverRealIp, '10.0.0.5') === '198.51.100.20',
    'trusted proxy + X-Real-IP fallback'
);

$serverInvalidXff = [
    'REMOTE_ADDR' => '10.0.0.5',
    'HTTP_X_FORWARDED_FOR' => 'garbage, more-garbage',
];
check(
    RequestContext::clientIp($serverInvalidXff, '10.0.0.5') === '10.0.0.5',
    'trusted proxy + invalid XFF → REMOTE_ADDR fallback'
);

// --------------------------------------------------------------------
// scheme — XFP respect only when trusted
// --------------------------------------------------------------------
$xfpUntrusted = [
    'REMOTE_ADDR' => '203.0.113.5',
    'HTTP_X_FORWARDED_PROTO' => 'https',
];
check(
    RequestContext::scheme($xfpUntrusted, '') === 'http',
    'untrusted XFP → ignore, fallback to TLS state'
);

$xfpTrusted = [
    'REMOTE_ADDR' => '10.0.0.5',
    'HTTP_X_FORWARDED_PROTO' => 'https',
];
check(
    RequestContext::scheme($xfpTrusted, '10.0.0.5') === 'https',
    'trusted XFP=https → https'
);

$xfpTrustedHttp = [
    'REMOTE_ADDR' => '10.0.0.5',
    'HTTP_X_FORWARDED_PROTO' => 'http',
];
check(
    RequestContext::scheme($xfpTrustedHttp, '10.0.0.5') === 'http',
    'trusted XFP=http → http'
);

$tlsLocal = [
    'REMOTE_ADDR' => '203.0.113.5',
    'HTTPS' => 'on',
];
check(
    RequestContext::scheme($tlsLocal, '') === 'https',
    'no proxy, HTTPS=on → https'
);

$port443 = [
    'REMOTE_ADDR' => '203.0.113.5',
    'SERVER_PORT' => '443',
];
check(
    RequestContext::scheme($port443, '') === 'https',
    'port 443 → https'
);

check(
    RequestContext::isSecure(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], '10.0.0.5'),
    'isSecure() === true when XFP=https + trusted'
);
check(
    !RequestContext::isSecure(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], ''),
    'isSecure() === false when XFP=https + untrusted'
);

echo "RequestContext tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
