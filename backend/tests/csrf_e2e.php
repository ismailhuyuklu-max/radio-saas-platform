<?php

declare(strict_types=1);

/** CSRF double-submit E2E: cookie-auth mutations require X-CSRF-Token. */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;

$pdo = PdoFactory::fromEnv();
$base = rtrim(getenv('API_GATEWAY_URL') ?: 'http://nginx/api/v1', '/');
$adminUser = getenv('ADMIN_USERNAME') ?: 'admin';
$adminPass = getenv('ADMIN_PASSWORD') ?: '123456';

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) { $passed++; echo "  OK: {$m}\n"; }
    else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}

/** @return array{0:int,1:string,2:array<string,string>} [status, body, setCookies] */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsize);
    $resBody = substr($raw, $hsize);
    $cookies = [];
    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $head, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $cookies[trim($row[1])] = trim($row[2]);
        }
    }
    return [$code, $resBody, $cookies];
}

// 1. Login (cookie auth) — capture session + csrf cookies
[$code, , $cookies] = http(
    'POST',
    $base . '/auth/login',
    ['Content-Type: application/json', 'Accept: application/json'],
    json_encode(['username' => $adminUser, 'password' => $adminPass])
);
check($code === 200, "login → 200 (got {$code})");
$session = $cookies['radio_session'] ?? '';
$csrf = $cookies['radio_csrf'] ?? '';
check($session !== '', 'login sets radio_session cookie');
check($csrf !== '', 'login sets radio_csrf cookie');

$cookieHeader = 'Cookie: radio_session=' . $session . '; radio_csrf=' . $csrf;
$campaignId = null;

try {
    // 2. GET with cookie, no CSRF header → allowed (reads are exempt)
    [$code] = http('GET', $base . '/ad-campaigns', [$cookieHeader, 'Accept: application/json']);
    check($code === 200, "cookie GET (no CSRF) → 200 (got {$code})");

    // 3. Mutating with cookie, NO CSRF header → 403
    [$code] = http(
        'POST',
        $base . '/ad-campaigns',
        [$cookieHeader, 'Content-Type: application/json'],
        json_encode(['advertiser_name' => 'CSRF Test', 'target_regions' => ['marmara'], 'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d')])
    );
    check($code === 403, "cookie POST without CSRF header → 403 (got {$code})");

    // 4. Mutating with cookie + correct CSRF header → success
    [$code, $body] = http(
        'POST',
        $base . '/ad-campaigns',
        [$cookieHeader, 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
        json_encode(['advertiser_name' => 'CSRF Test', 'target_regions' => ['marmara'], 'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d')])
    );
    check($code === 201, "cookie POST with CSRF header → 201 (got {$code})");
    $campaignId = json_decode($body, true)['result']['id'] ?? null;

    // 5. Mutating with cookie + WRONG CSRF header → 403
    [$code] = http(
        'POST',
        $base . '/ad-campaigns',
        [$cookieHeader, 'Content-Type: application/json', 'X-CSRF-Token: wrong-token'],
        json_encode(['advertiser_name' => 'x', 'target_regions' => ['ege'], 'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d')])
    );
    check($code === 403, "cookie POST with wrong CSRF header → 403 (got {$code})");
} finally {
    if ($campaignId !== null) {
        $pdo->prepare('DELETE FROM ad_campaigns WHERE id = :id')->execute(['id' => $campaignId]);
    }
    if ($session !== '') {
        $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :h')->execute(['h' => hash('sha256', $session)]);
    }
}

echo "CSRF E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
