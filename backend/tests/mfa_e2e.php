<?php

declare(strict_types=1);

/** End-to-end MFA flow against the live gateway. */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AdminSessionRepository;
use RadioSaaS\Service\TotpService;

$pdo = PdoFactory::fromEnv();
$base = rtrim(getenv('API_GATEWAY_URL') ?: 'http://nginx/api/v1', '/');
$sessions = new AdminSessionRepository($pdo);

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) {
        $passed++;
        echo "  OK: {$m}\n";
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$m}\n");
    }
}

function call(string $method, string $url, ?array $body, ?string $token): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true) ?? []];
}

// temp user with known password
$username = 'mfa_test_' . bin2hex(random_bytes(3));
$password = 'Secret123!';
$pdo->prepare("INSERT INTO users (username,password_hash,real_name,roles,is_active) VALUES (:u,:p,'MFA Test',CAST('[\"viewer\"]' AS jsonb),true) RETURNING id")
    ->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_BCRYPT)]);
$userId = (string) $pdo->query("SELECT id FROM users WHERE username = " . $pdo->quote($username))->fetchColumn();
$sessionToken = $sessions->create($userId);

try {
    // 1. setup
    [$c, $b] = call('POST', $base . '/auth/mfa/setup', [], $sessionToken);
    check($c === 200 && !empty($b['result']['secret']), 'setup returns a secret');
    $secret = $b['result']['secret'] ?? '';
    check(str_starts_with((string) ($b['result']['otpauth_uri'] ?? ''), 'otpauth://'), 'setup returns otpauth uri');

    // 2. enable with a valid code
    $code = TotpService::codeAt($secret, time());
    [$c, $b] = call('POST', $base . '/auth/mfa/enable', ['code' => $code], $sessionToken);
    check($c === 200 && ($b['result']['enabled'] ?? false) === true, 'enable activates MFA');
    $recovery = $b['result']['recovery_codes'] ?? [];
    check(count($recovery) === 8, 'enable returns 8 recovery codes');

    // 3. enable rejects bad code (fresh setup needed though) — verify wrong code path on a 2nd setup
    [, $b2] = call('POST', $base . '/auth/mfa/setup', [], $sessionToken);
    [$c] = call('POST', $base . '/auth/mfa/enable', ['code' => '000000'], $sessionToken);
    check($c === 400, 'enable rejects invalid code (400)');
    // re-enable properly so login flow has MFA on
    $okCode = TotpService::codeAt($b2['result']['secret'], time());
    [$c, $b] = call('POST', $base . '/auth/mfa/enable', ['code' => $okCode], $sessionToken);
    $secret = $b2['result']['secret'];
    $recovery = $b['result']['recovery_codes'] ?? [];

    // 4. login now requires MFA
    [$c, $b] = call('POST', $base . '/auth/login', ['username' => $username, 'password' => $password], null);
    check($c === 200 && ($b['result']['mfa_required'] ?? false) === true, 'login returns mfa_required');
    $mfaToken = $b['result']['mfa_token'] ?? '';
    check($mfaToken !== '', 'login returns mfa challenge token');
    check(!isset($b['result']['userId']), 'login does NOT return a session yet');

    // 5. verify with TOTP code → session
    [$c, $b] = call('POST', $base . '/auth/mfa/verify', ['mfa_token' => $mfaToken, 'code' => TotpService::codeAt($secret, time())], null);
    check($c === 200 && !empty($b['result']['userId']), 'mfa verify issues a session');

    // 6. verify rejects wrong code
    [$c2, $bb] = call('POST', $base . '/auth/login', ['username' => $username, 'password' => $password], null);
    [$c, $b] = call('POST', $base . '/auth/mfa/verify', ['mfa_token' => $bb['result']['mfa_token'], 'code' => '000000'], null);
    check($c === 401, 'mfa verify rejects wrong code (401)');

    // 7. recovery code works once
    [, $bb] = call('POST', $base . '/auth/login', ['username' => $username, 'password' => $password], null);
    [$c, $b] = call('POST', $base . '/auth/mfa/verify', ['mfa_token' => $bb['result']['mfa_token'], 'code' => $recovery[0]], null);
    check($c === 200 && !empty($b['result']['userId']), 'recovery code logs in');
    // same recovery code rejected second time
    [, $bb] = call('POST', $base . '/auth/login', ['username' => $username, 'password' => $password], null);
    [$c] = call('POST', $base . '/auth/mfa/verify', ['mfa_token' => $bb['result']['mfa_token'], 'code' => $recovery[0]], null);
    check($c === 401, 'used recovery code is rejected second time');

    // 8. disable with a valid code
    [$c, $b] = call('POST', $base . '/auth/mfa/disable', ['code' => TotpService::codeAt($secret, time())], $sessionToken);
    check($c === 200 && ($b['result']['enabled'] ?? true) === false, 'disable turns MFA off');

    // 9. after disable, login no longer requires MFA
    [$c, $b] = call('POST', $base . '/auth/login', ['username' => $username, 'password' => $password], null);
    check($c === 200 && !empty($b['result']['userId']), 'login works without MFA after disable');
} finally {
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :id')->execute(['id' => $userId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
}

echo "MFA E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
