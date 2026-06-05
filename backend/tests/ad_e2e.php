<?php

declare(strict_types=1);

/** Ad-traffic + revenue E2E against the live gateway. */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AdminSessionRepository;

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

function call(string $method, string $url, string $token, ?array $body = null): array
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
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $resp, true)];
}

// super token (seeded admin)
$adminId = (string) $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();
$superToken = $sessions->create($adminId);

// viewer token
$pdo->prepare("INSERT INTO users (username,password_hash,real_name,roles,is_active) VALUES (:u,:p,'Ad Viewer',CAST('[\"viewer\"]' AS jsonb),true) RETURNING id")
    ->execute(['u' => 'ad_viewer_' . bin2hex(random_bytes(3)), 'p' => password_hash('x', PASSWORD_BCRYPT)]);
$viewerId = (string) $pdo->query("SELECT id FROM users WHERE real_name='Ad Viewer' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$viewerToken = $sessions->create($viewerId);

$campaignId = null;
try {
    // create
    [$c, $b] = call('POST', $base . '/ad-campaigns', $superToken, [
        'advertiser_name' => 'E2E Reklam A.Ş.',
        'pricing_model' => 'cpm',
        'rate' => 60,
        'budget' => 500000,
        'spots_per_day' => 4,
        'target_regions' => ['marmara', 'ege'],
        'target_parts' => ['news'],
        'starts_at' => date('Y-m-d', strtotime('-2 days')),
        'ends_at' => date('Y-m-d', strtotime('+7 days')),
        'status' => 'active',
    ]);
    check($c === 201, "super POST /ad-campaigns → 201 (got {$c})");
    $campaignId = $b['result']['id'] ?? null;
    check($campaignId !== null, 'created campaign returns id');

    // list + metrics + summary
    [$c, $b] = call('GET', $base . '/ad-campaigns', $superToken);
    check($c === 200, "GET /ad-campaigns → 200 (got {$c})");
    check(isset($b['summary']['total_projected_revenue']), 'summary has total_projected_revenue');
    $found = null;
    foreach ($b['campaigns'] ?? [] as $row) {
        if (($row['id'] ?? '') === $campaignId) {
            $found = $row;
        }
    }
    check($found !== null, 'created campaign appears in list');
    check(isset($found['metrics']['projected_revenue']) && $found['metrics']['projected_revenue'] > 0, 'campaign has positive projected revenue');
    check($found['metrics']['delivered_revenue'] > 0, 'campaign has delivered revenue (run already started)');

    // record an actual airing → delivered revenue should switch to real data
    [$c, $b] = call('POST', $base . '/ad-campaigns/' . $campaignId . '/airings', $superToken, [
        'region_code' => 'marmara', 'part_code' => 'news', 'impressions' => 1000000,
    ]);
    check($c === 201, "record airing → 201 (got {$c})");
    [, $b] = call('GET', $base . '/ad-campaigns', $superToken);
    $afterAir = null;
    foreach ($b['campaigns'] ?? [] as $row) {
        if (($row['id'] ?? '') === $campaignId) {
            $afterAir = $row;
        }
    }
    check(($afterAir['metrics']['has_actuals'] ?? false) === true, 'campaign now flagged has_actuals');
    check(($afterAir['metrics']['delivered_impressions'] ?? 0) === 1000000, 'delivered impressions from recorded airing');
    // viewer cannot record airings (ad:write)
    [$c] = call('POST', $base . '/ad-campaigns/' . $campaignId . '/airings', $viewerToken, ['region_code' => 'ege']);
    check($c === 403, "viewer cannot record airing → 403 (got {$c})");

    // RBAC: viewer can read, cannot write
    [$c] = call('GET', $base . '/ad-campaigns', $viewerToken);
    check($c === 200, "viewer GET /ad-campaigns → 200 (got {$c})");
    [$c] = call('POST', $base . '/ad-campaigns', $viewerToken, ['advertiser_name' => 'x']);
    check($c === 403, "viewer POST /ad-campaigns → 403 (got {$c})");

    // update
    [$c, $b] = call('PATCH', $base . '/ad-campaigns/' . $campaignId, $superToken, ['status' => 'paused']);
    check($c === 200 && ($b['result']['status'] ?? '') === 'paused', 'PATCH updates status to paused');

    // delete
    [$c] = call('DELETE', $base . '/ad-campaigns/' . $campaignId, $superToken);
    check($c === 200, "DELETE /ad-campaigns → 200 (got {$c})");
    $campaignId = null;
} finally {
    if ($campaignId !== null) {
        $pdo->prepare('DELETE FROM ad_campaigns WHERE id = :id')->execute(['id' => $campaignId]);
    }
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id IN (:a, :b)')->execute(['a' => $adminId, 'b' => $viewerId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $viewerId]);
}

echo "Ad E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
