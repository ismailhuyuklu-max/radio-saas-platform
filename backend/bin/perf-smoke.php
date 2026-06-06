<?php

declare(strict_types=1);

/**
 * Hot-path latency smoke. After seed-load.php has populated 500+ stations /
 * 5000+ users / 100k+ media rows, this script times the key endpoints and
 * asserts none exceed loose budgets (300 ms by default).
 *
 * Run inside the app network:
 *   docker compose exec php-fpm php bin/perf-smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AdminSessionRepository;

$pdo = PdoFactory::fromEnv();
$base = rtrim(getenv('API_GATEWAY_URL') ?: 'http://nginx/api/v1', '/');
$budget = (int) (getenv('PERF_BUDGET_MS') ?: 300);

$superId = (string) $pdo->query(
    "SELECT id FROM users WHERE 'super' = ANY (SELECT jsonb_array_elements_text(roles)) LIMIT 1"
)->fetchColumn();
$sessions = new AdminSessionRepository($pdo);
$adminToken = $sessions->create($superId);

function timed(string $method, string $url, ?string $token): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $start = microtime(true);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (int) round((microtime(true) - $start) * 1000)];
}

$cases = [
    ['GET', '/stations?limit=200'],
    ['GET', '/plans?date=' . date('Y-m-d')],
    ['GET', '/plans/range?start=' . date('Y-m-d') . '&end=' . date('Y-m-d', strtotime('+6 days'))],
    ['GET', '/media-library'],
    ['GET', '/ad-campaigns?limit=200'],
    ['GET', '/audit/logs?limit=100'],
    ['GET', '/reports/breakdown/province'],
    ['GET', '/reports/breakdown/customer'],
];

$failed = 0;
echo "Perf budget: {$budget}ms\n";
foreach ($cases as [$method, $path]) {
    [$code, $ms] = timed($method, $base . $path, $adminToken);
    $ok = $code < 500 && $ms <= $budget;
    if (!$ok) {
        $failed++;
    }
    printf("  %-6s %-60s %3d %5dms %s\n", $method, $path, $code, $ms, $ok ? 'OK' : 'FAIL');
}

$sessions->revokeByToken($adminToken);

echo $failed === 0 ? "All within budget.\n" : "{$failed} endpoint(s) exceeded budget.\n";
exit($failed === 0 ? 0 : 1);
