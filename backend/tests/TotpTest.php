<?php

declare(strict_types=1);

/**
 * TOTP test using the official RFC 6238 test vectors.
 * Run: php backend/tests/TotpTest.php
 */

require __DIR__ . '/../src/Service/TotpService.php';

use RadioSaaS\Service\TotpService;

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

// RFC 6238 seed "12345678901234567890" → base32
$secret = TotpService::base32Encode('12345678901234567890');
check($secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 of RFC seed');

// base32 round-trip
check(TotpService::base32Decode($secret) === '12345678901234567890', 'base32 decode round-trip');

// RFC 6238 vectors (SHA1), truncated to 6 digits
$vectors = [
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
];
foreach ($vectors as [$time, $expected]) {
    check(TotpService::codeAt($secret, $time) === $expected, "RFC vector t={$time} → {$expected}");
}

// verify with drift window
$now = 1111111109;
check(TotpService::verify($secret, '081804', $now), 'verify exact code');
check(TotpService::verify($secret, TotpService::codeAt($secret, $now - 30), $now, 1), 'verify previous step within window');
check(!TotpService::verify($secret, '000000', $now), 'verify rejects wrong code');
check(!TotpService::verify($secret, 'abc', $now), 'verify rejects non-numeric');
check(!TotpService::verify($secret, '12345', $now), 'verify rejects wrong length');

// secret generation
$gen = TotpService::generateSecret();
check(strlen($gen) >= 16 && preg_match('/^[A-Z2-7]+$/', $gen) === 1, 'generateSecret is valid base32');
$genCode = TotpService::codeAt($gen, $now);
check(TotpService::verify($gen, $genCode, $now), 'generated secret self-verifies');

// provisioning URI
$uri = TotpService::provisioningUri($secret, 'admin', 'Aircast Pro');
check(str_starts_with($uri, 'otpauth://totp/'), 'provisioning uri scheme');
check(str_contains($uri, 'secret=' . $secret), 'provisioning uri carries secret');
check(str_contains($uri, 'issuer=Aircast'), 'provisioning uri carries issuer');

// recovery codes
$codes = TotpService::generateRecoveryCodes(8);
check(count($codes) === 8, 'generates 8 recovery codes');
check(preg_match('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $codes[0]) === 1, 'recovery code format');
$hash = TotpService::hashRecoveryCode($codes[0]);
check($hash === TotpService::hashRecoveryCode(strtolower($codes[0])), 'recovery hash case-insensitive');
check(strlen($hash) === 64, 'recovery hash is sha256');

echo "TOTP tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
