<?php

declare(strict_types=1);

/** Security features E2E: rate-limit, password change/reset, MFA admin reset, sessions. */

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
    if ($c) { $passed++; echo "  OK: {$m}\n"; }
    else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}
function call(string $method, string $url, ?array $body, ?string $token): array
{
    $h = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) { $h[] = 'Authorization: Bearer ' . $token; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true) ?? []];
}
function mkUser(PDO $pdo, string $pw, array $roles = ['viewer']): array
{
    $u = 'sec_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO users (username,password_hash,real_name,roles,is_active) VALUES (:u,:p,'Sec Test',CAST(:r AS jsonb),true) RETURNING id")
        ->execute(['u' => $u, 'p' => password_hash($pw, PASSWORD_BCRYPT), 'r' => json_encode($roles)]);
    $id = (string) $pdo->query("SELECT id FROM users WHERE username=" . $pdo->quote($u))->fetchColumn();
    return ['id' => $id, 'username' => $u];
}

$adminId = (string) $pdo->query("SELECT id FROM users WHERE username='admin' LIMIT 1")->fetchColumn();
$superToken = $sessions->create($adminId);
$created = [];

try {
    // 1. RATE LIMIT
    $rl = mkUser($pdo, 'Correct1!');
    $created[] = $rl['id'];
    for ($i = 1; $i <= 5; $i++) {
        [$c] = call('POST', $base . '/auth/login', ['username' => $rl['username'], 'password' => 'wrong'], null);
        if ($i < 5) { check($c === 401, "rate-limit attempt {$i} → 401"); }
    }
    [$c] = call('POST', $base . '/auth/login', ['username' => $rl['username'], 'password' => 'wrong'], null);
    check($c === 429, "6th attempt → 429 locked (got {$c})");
    // even correct password is blocked while locked
    [$c] = call('POST', $base . '/auth/login', ['username' => $rl['username'], 'password' => 'Correct1!'], null);
    check($c === 429, 'correct password blocked while locked → 429');
    // Clear both the username and the IP throttle (the failed attempts above also
    // locked this client IP — that is the new IP-based protection at work).
    $pdo->prepare('DELETE FROM login_throttle WHERE username = :u')->execute(['u' => $rl['username']]);
    $pdo->prepare("DELETE FROM login_throttle WHERE username LIKE 'ip:%'")->execute();
    [$c] = call('POST', $base . '/auth/login', ['username' => $rl['username'], 'password' => 'Correct1!'], null);
    check($c === 200, 'login succeeds after throttle cleared (username + IP)');

    // 2. PASSWORD CHANGE (self)
    $pc = mkUser($pdo, 'OldPass1!');
    $created[] = $pc['id'];
    $pcToken = $sessions->create($pc['id']);
    [$c] = call('POST', $base . '/auth/password', ['current_password' => 'WRONG', 'new_password' => 'NewPass1!'], $pcToken);
    check($c === 400, 'change password rejects wrong current (400)');
    [$c] = call('POST', $base . '/auth/password', ['current_password' => 'OldPass1!', 'new_password' => 'NewPass1!'], $pcToken);
    check($c === 200, 'change password succeeds with correct current');
    [$c] = call('POST', $base . '/auth/login', ['username' => $pc['username'], 'password' => 'NewPass1!'], null);
    check($c === 200, 'login works with new password');
    [$c] = call('POST', $base . '/auth/login', ['username' => $pc['username'], 'password' => 'OldPass1!'], null);
    check($c === 401, 'old password no longer works');
    $pdo->prepare('DELETE FROM login_throttle WHERE username = :u')->execute(['u' => $pc['username']]);

    // 3. ADMIN PASSWORD RESET
    $ar = mkUser($pdo, 'Initial1!');
    $created[] = $ar['id'];
    [$c] = call('POST', $base . '/users/' . $ar['id'] . '/password', ['new_password' => 'AdminSet1!'], $superToken);
    check($c === 200, 'admin reset password → 200');
    [$c] = call('POST', $base . '/auth/login', ['username' => $ar['username'], 'password' => 'AdminSet1!'], null);
    check($c === 200, 'login works with admin-set password');
    // a viewer cannot reset passwords
    $viewerTok = $sessions->create($ar['id']);
    [$c] = call('POST', $base . '/users/' . $adminId . '/password', ['new_password' => 'hack12'], $viewerTok);
    check($c === 403, 'non-admin cannot reset passwords (403)');

    // 4. ADMIN MFA RESET
    $mr = mkUser($pdo, 'MfaPass1!');
    $created[] = $mr['id'];
    $mrToken = $sessions->create($mr['id']);
    [, $s] = call('POST', $base . '/auth/mfa/setup', [], $mrToken);
    $secret = $s['result']['secret'];
    call('POST', $base . '/auth/mfa/enable', ['code' => TotpService::codeAt($secret, time())], $mrToken);
    [$c, $b] = call('POST', $base . '/auth/login', ['username' => $mr['username'], 'password' => 'MfaPass1!'], null);
    check($c === 200 && ($b['result']['mfa_required'] ?? false) === true, 'MFA user login requires MFA');
    [$c] = call('POST', $base . '/users/' . $mr['id'] . '/mfa/reset', [], $superToken);
    check($c === 200, 'admin MFA reset → 200');
    [$c, $b] = call('POST', $base . '/auth/login', ['username' => $mr['username'], 'password' => 'MfaPass1!'], null);
    check($c === 200 && !empty($b['result']['userId']), 'login no longer requires MFA after admin reset');

    // 5. SESSION MANAGEMENT
    $sm = mkUser($pdo, 'SessPass1!');
    $created[] = $sm['id'];
    $t1 = $sessions->create($sm['id']);
    $t2 = $sessions->create($sm['id']);
    [$c, $b] = call('GET', $base . '/auth/sessions', null, $t1);
    check($c === 200 && count($b['result']) >= 2, 'sessions list shows >=2 active');
    $currentFlags = array_filter($b['result'], fn ($s) => $s['is_current'] ?? false);
    check(count($currentFlags) === 1, 'exactly one session flagged is_current');
    [$c, $b] = call('POST', $base . '/auth/sessions/revoke-others', null, $t1);
    check($c === 200 && ($b['result']['revoked'] ?? 0) >= 1, 'revoke-others revokes the other session');
    [$c] = call('GET', $base . '/auth/sessions', null, $t2);
    check($c === 401, 'revoked session token is now rejected (401)');
    [$c] = call('GET', $base . '/auth/sessions', null, $t1);
    check($c === 200, 'current session still valid after revoke-others');
} finally {
    foreach ($created as $id) {
        $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
    // Only revoke the token we created — never wipe the seeded admin's sessions.
    $pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = :h')->execute(['h' => hash('sha256', $superToken)]);
    $pdo->prepare('DELETE FROM login_throttle WHERE username LIKE :p')->execute(['p' => 'ip:%']);
}

echo "Security E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
