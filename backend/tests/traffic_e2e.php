<?php

declare(strict_types=1);

/**
 * Traffic data-model end-to-end test (Faz 2).
 *
 * Exercises the live API gateway for the il/grup/kampanya targeting model:
 *   - GET /traffic/provinces returns the 81 provinces
 *   - POST /plans/bulk with il (province) targeting writes il-keyed plans
 *   - il-level conflict engine skips a duplicate il slot but allows a second il
 *   - radio-group targeting expands a group into its member stations
 *   - campaign_id is persisted on the created plans
 *
 * Run inside the app network:
 *   docker compose exec php-fpm php tests/traffic_e2e.php
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

/** @return array{0:int,1:array} [status, decoded body] */
function api(string $method, string $url, string $token, ?array $body = null): array
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
        CURLOPT_POSTFIELDS => $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);

    return [$code, is_array($decoded) ? $decoded : []];
}

function makeUser(PDO $pdo, array $roles): string
{
    $username = 'traffic_test_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, real_name, roles, is_active)
         VALUES (:u, :p, :r, CAST(:roles AS jsonb), true) RETURNING id'
    );
    $stmt->execute([
        'u' => $username,
        'p' => password_hash('traffic-test', PASSWORD_BCRYPT),
        'r' => 'Traffic Test',
        'roles' => json_encode($roles),
    ]);

    return (string) $stmt->fetchColumn();
}

$sessions = new AdminSessionRepository($pdo);
$superId = makeUser($pdo, ['super']);
$token = $sessions->create($superId);

// A unique far-future date so this test never collides with seed/demo plans.
$planDate = '2031-03-1' . random_int(0, 9);
$slot = '08:00';

// Track created plan ids for cleanup.
$createdPlanIds = [];

try {
    // --- Provinces metadata -------------------------------------------------
    [$code, $body] = api('GET', $base . '/traffic/provinces', $token);
    check($code === 200, "GET /traffic/provinces → 200 (got {$code})");
    $provinces = $body['provinces'] ?? [];
    check(count($provinces) === 81, 'provinces returns all 81 il (got ' . count($provinces) . ')');
    $names = array_column($provinces, 'name');
    check(in_array('İstanbul', $names, true), 'İstanbul present (Turkish UTF-8 intact)');
    check(in_array('Şanlıurfa', $names, true), 'Şanlıurfa present');

    // --- İl-level bulk plan -------------------------------------------------
    $payloadIl = [
        'target_provinces' => ['İstanbul'],
        'slots' => [['slot_time' => $slot, 'part_code' => 'news', 'content_title' => 'İl Haber']],
        'start_date' => $planDate,
        'repeat_days' => 1,
    ];
    [$code, $body] = api('POST', $base . '/plans/bulk', $token, $payloadIl);
    check($code === 201, "POST /plans/bulk (İstanbul) → 201 (got {$code})");
    check(($body['result']['created'] ?? 0) === 1, 'İstanbul plan created (1)');

    // Verify il-keyed row in DB.
    $row = $pdo->prepare(
        "SELECT province, campaign_id FROM content_plans
         WHERE plan_date = :d AND slot_time = :s AND province = 'İstanbul' LIMIT 1"
    );
    $row->execute(['d' => $planDate, 's' => $slot]);
    $planRow = $row->fetch();
    check($planRow !== false && $planRow['province'] === 'İstanbul', 'plan persisted with province=İstanbul');

    // --- İl-level conflict engine ------------------------------------------
    [$code, $body] = api('POST', $base . '/plans/bulk', $token, $payloadIl);
    check(($body['result']['skipped'] ?? 0) === 1 && ($body['result']['created'] ?? -1) === 0,
        'duplicate İstanbul slot skipped by il conflict engine');

    // A different il, same slot/date, must NOT conflict.
    $payloadIl2 = $payloadIl;
    $payloadIl2['target_provinces'] = ['Ankara'];
    [$code, $body] = api('POST', $base . '/plans/bulk', $token, $payloadIl2);
    check(($body['result']['created'] ?? 0) === 1, 'Ankara plan created (different il, no false conflict)');

    // --- Faz 5: calendar range feed ----------------------------------------
    [$code, $body] = api(
        'GET',
        $base . '/plans/range?start=' . $planDate . '&end=' . $planDate . '&region=marmara',
        $token
    );
    check($code === 200, "GET /plans/range → 200 (got {$code})");
    check(($body['counts'][$planDate] ?? 0) >= 1, 'range feed returns per-day counts for the marmara İstanbul plan');
    check(is_array($body['plans'] ?? null), 'range feed returns plans array');

    // --- Campaign link ------------------------------------------------------
    // Create a dedicated campaign so planned/aired/missed are fully controlled
    // (reusing a demo campaign would carry pre-seeded airings).
    $campaignDate = '2031-04-0' . random_int(1, 9);
    [$cc, $cbody] = api('POST', $base . '/ad-campaigns', $token, [
        'advertiser_name' => 'E2E Trafik Reklamvereni',
        'pricing_model' => 'cpm',
        'rate' => 50,
        'budget' => 10000,
        'spots_per_day' => 1,
        'target_regions' => ['ege'],
        'starts_at' => $campaignDate,
        'ends_at' => $campaignDate,
        'status' => 'active',
    ]);
    $campaignId = (string) ($cbody['result']['id'] ?? '');
    check($cc === 201 && $campaignId !== '', 'dedicated test campaign created');
    if ($campaignId !== '') {
        $payloadCamp = [
            'target_provinces' => ['İzmir'],
            'slots' => [['slot_time' => '10:00', 'part_code' => 'ad', 'content_title' => 'Kampanya Spotu']],
            'start_date' => $campaignDate,
            'repeat_days' => 1,
            'campaign_id' => (string) $campaignId,
        ];
        [$code, $body] = api('POST', $base . '/plans/bulk', $token, $payloadCamp);
        check(($body['result']['created'] ?? 0) === 1, 'İzmir campaign plan created');
        $r = $pdo->prepare("SELECT campaign_id FROM content_plans WHERE plan_date = :d AND province = 'İzmir' LIMIT 1");
        $r->execute(['d' => $campaignDate]);
        check((string) $r->fetchColumn() === (string) $campaignId, 'plan linked to campaign_id');

        // Faz 3: Reklam Trafik columns derived from the campaign↔plan link.
        [$code, $body] = api('GET', $base . '/ad-campaigns?limit=500', $token);
        check($code === 200, "GET /ad-campaigns → 200 (got {$code})");
        $found = null;
        foreach (($body['campaigns'] ?? []) as $c) {
            if ((string) ($c['id'] ?? '') === (string) $campaignId) {
                $found = $c;
                break;
            }
        }
        check($found !== null && isset($found['traffic']), 'campaign carries traffic columns');
        if ($found !== null) {
            $t = $found['traffic'];
            check(($t['planned'] ?? 0) >= 1, 'traffic.planned reflects linked plan (>=1)');
            check(($t['remaining'] ?? 0) >= 1, 'traffic.remaining counts future spot');
            check(($t['missed'] ?? -1) === 0, 'traffic.missed is 0 for future-dated spot');
            check(isset($body['traffic_summary']['planned']), 'traffic_summary present');
        }

        // --- Faz 6: reporting breakdowns (il / müşteri) --------------------
        [$code, $body] = api('GET', $base . '/reports/breakdown/province', $token);
        check($code === 200, "GET /reports/breakdown/province → 200 (got {$code})");
        $provinceRows = $body['rows'] ?? [];
        $hasIzmir = false;
        foreach ($provinceRows as $r) {
            if (($r['province'] ?? '') === 'İzmir') {
                $hasIzmir = true;
            }
        }
        check($hasIzmir, 'province breakdown includes İzmir (campaign plan)');

        [$code, $body] = api('GET', $base . '/reports/breakdown/customer', $token);
        check($code === 200, "GET /reports/breakdown/customer → 200 (got {$code})");
        $custRows = $body['rows'] ?? [];
        $hasCustomer = false;
        foreach ($custRows as $r) {
            if (($r['advertiser_name'] ?? '') === 'E2E Trafik Reklamvereni') {
                $hasCustomer = true;
                check(($r['planned_spots'] ?? 0) >= 1, 'customer breakdown counts planned spots');
            }
        }
        check($hasCustomer, 'customer breakdown includes the test advertiser');
    } else {
        echo "  SKIP: no ad_campaigns row to link (campaign test skipped)\n";
    }

    // --- Radio group targeting ---------------------------------------------
    $stationStmt = $pdo->query('SELECT id FROM stations LIMIT 2');
    $stationIds = $stationStmt !== false ? array_map('strval', $stationStmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    if (count($stationIds) >= 1) {
        [$code, $body] = api('POST', $base . '/traffic/groups', $token, [
            'name' => 'E2E Test Grubu ' . bin2hex(random_bytes(3)),
            'description' => 'geçici',
            'station_ids' => $stationIds,
        ]);
        check($code === 201, "POST /traffic/groups → 201 (got {$code})");
        $groupId = (string) ($body['result']['id'] ?? '');
        check($groupId !== '', 'group created with id');
        check(count($body['result']['station_ids'] ?? []) === count($stationIds), 'group members set');

        $groupDate = '2031-05-0' . random_int(1, 9);
        [$code, $body] = api('POST', $base . '/plans/bulk', $token, [
            'group_ids' => [$groupId],
            'slots' => [['slot_time' => '12:00', 'part_code' => 'news', 'content_title' => 'Grup Yayını']],
            'start_date' => $groupDate,
            'repeat_days' => 1,
        ]);
        check(($body['result']['created'] ?? 0) === count($stationIds),
            'group expanded into ' . count($stationIds) . ' station plans');

        // Cleanup group + its plans.
        $pdo->prepare('DELETE FROM content_plans WHERE plan_date = :d')->execute(['d' => $groupDate]);
        api('DELETE', $base . '/traffic/groups/' . $groupId, $token);
    } else {
        echo "  SKIP: no stations to build a group (group test skipped)\n";
    }
    // --- Faz 4: smart placement + timeline bulk operations -----------------
    // İstanbul has a news plan at 08:00 (created above) → suggest a sponsor
    // read for it and fill empty prime slots.
    [$code, $body] = api(
        'GET',
        $base . '/plans/suggest?region=marmara&date=' . $planDate,
        $token
    );
    check($code === 200, "GET /plans/suggest → 200 (got {$code})");
    $suggestions = $body['result']['suggestions'] ?? [];
    $hasSponsor = false;
    foreach ($suggestions as $s) {
        if (($s['part_code'] ?? '') === 'sponsor' && ($s['slot_time'] ?? '') === '08:00') {
            $hasSponsor = true;
        }
    }
    check($hasSponsor, 'smart placement suggests a sponsor read for the 08:00 news');

    // Bulk move: shift İstanbul plan by +1 slot (08:00 → 10:00).
    $idRow = $pdo->prepare(
        "SELECT id FROM content_plans WHERE plan_date = :d AND province = 'İstanbul' LIMIT 1"
    );
    $idRow->execute(['d' => $planDate]);
    $istanbulPlanId = (string) $idRow->fetchColumn();
    if ($istanbulPlanId !== '') {
        [$code, $body] = api('POST', $base . '/plans/bulk-move', $token, [
            'ids' => [$istanbulPlanId],
            'slot_shift' => 1,
        ]);
        check(($body['result']['written'] ?? 0) === 1, 'bulk-move shifted 1 plan');
        $chk = $pdo->prepare('SELECT slot_time FROM content_plans WHERE id = :id');
        $chk->execute(['id' => $istanbulPlanId]);
        check(substr((string) $chk->fetchColumn(), 0, 5) === '10:00', 'plan moved to 10:00 slot');

        // Bulk delete it.
        [$code, $body] = api('POST', $base . '/plans/bulk-delete', $token, [
            'ids' => [$istanbulPlanId],
        ]);
        check(($body['result']['deleted'] ?? 0) === 1, 'bulk-delete removed the plan');
        $chk->execute(['id' => $istanbulPlanId]);
        check($chk->fetchColumn() === false, 'plan no longer exists after bulk-delete');
    }
} finally {
    // Remove all plans this test created.
    $pdo->prepare("DELETE FROM content_plans WHERE plan_date IN (:a, :b, :c)")
        ->execute(['a' => $planDate, 'b' => $campaignDate ?? '2000-01-01', 'c' => '2000-01-01']);
    $pdo->prepare("DELETE FROM content_plans WHERE content_title IN ('İl Haber','Kampanya Spotu','Grup Yayını')")
        ->execute();
    if (!empty($campaignId)) {
        $pdo->prepare('DELETE FROM content_plans WHERE campaign_id = :c')->execute(['c' => $campaignId]);
        $pdo->prepare('DELETE FROM ad_airings WHERE campaign_id = :c')->execute(['c' => $campaignId]);
        $pdo->prepare('DELETE FROM ad_campaigns WHERE id = :c')->execute(['c' => $campaignId]);
    }
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :id')->execute(['id' => $superId]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $superId]);
}

echo "\nTraffic E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
