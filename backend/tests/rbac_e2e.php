<?php

declare(strict_types=1);

/**
 * RBAC end-to-end test against the live API gateway.
 *
 * Creates throwaway viewer/editor users + session tokens, calls real HTTP
 * endpoints, asserts the expected 200/403 responses, then cleans up.
 *
 * Run inside the app network:
 *   docker compose exec php-fpm php bin/../tests/rbac_e2e.php
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AdminSessionRepository;

$pdo = PdoFactory::fromEnv();
$base = rtrim(getenv('API_GATEWAY_URL') ?: 'http://nginx/api/v1', '/');

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$msg}\n");
    }
}

function call(string $method, string $url, string $token): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $method === 'POST'
            ? json_encode(['name' => 'RBAC Test FM', 'region_code' => 'marmara', 'city_name' => 'Test'])
            : null,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function makeUser(PDO $pdo, array $roles): string
{
    $username = 'rbac_test_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, real_name, roles, is_active)
         VALUES (:u, :p, :r, CAST(:roles AS jsonb), true) RETURNING id'
    );
    $stmt->execute([
        'u' => $username,
        'p' => password_hash('rbac-test', PASSWORD_BCRYPT),
        'r' => 'RBAC Test',
        'roles' => json_encode($roles),
    ]);
    return (string) $stmt->fetchColumn();
}

$sessions = new AdminSessionRepository($pdo);

$viewerId = makeUser($pdo, ['viewer']);
$editorId = makeUser($pdo, ['editor']);
$viewerToken = $sessions->create($viewerId);
$editorToken = $sessions->create($editorId);

try {
    // viewer: can read, cannot write, cannot administer
    check(call('GET', $base . '/stations', $viewerToken) === 200, 'viewer GET /stations → 200');
    check(call('POST', $base . '/stations', $viewerToken) === 403, 'viewer POST /stations → 403');
    check(call('GET', $base . '/plans', $viewerToken) === 200, 'viewer GET /plans → 200');
    check(call('GET', $base . '/users', $viewerToken) === 403, 'viewer GET /users → 403');
    check(call('GET', $base . '/audit/logs', $viewerToken) === 403, 'viewer GET /audit/logs → 403');

    // editor: can write content, cannot write infra, cannot administer
    check(call('GET', $base . '/plans', $editorToken) === 200, 'editor GET /plans → 200');
    check(call('POST', $base . '/stations', $editorToken) === 403, 'editor POST /stations → 403');
    check(call('GET', $base . '/users', $editorToken) === 403, 'editor GET /users → 403');
} finally {
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id IN (:a, :b)')
        ->execute(['a' => $viewerId, 'b' => $editorId]);
    $pdo->prepare('DELETE FROM users WHERE id IN (:a, :b)')
        ->execute(['a' => $viewerId, 'b' => $editorId]);
}

echo "RBAC E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
