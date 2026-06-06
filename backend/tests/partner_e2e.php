<?php

declare(strict_types=1);

/**
 * Partner Radio Portal end-to-end test.
 *
 * Drives the full Aircast Radio Partner Portal flow against the live API:
 *   1. Admin provisions a station user → one-shot password returned
 *   2. Partner logs in with that password
 *   3. Partner reads /portal/me (own card), /portal/links (8 signed URLs),
 *      /portal/feeds, /portal/media, /portal/activity
 *   4. Tenant isolation: partner cannot reach admin endpoints
 *   5. Signed-URL feed is reachable WITHOUT a session
 *   6. Admin rotates the partner's tokens → old URL returns 403
 *   7. Admin rotates the partner's password → old credentials no longer work
 *
 * Run inside the app network:
 *   docker compose exec php-fpm php tests/partner_e2e.php
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

/** @return array{0:int,1:array,2:string} [status, decoded body, raw] */
function api(string $method, string $url, ?string $token = null, ?array $body = null): array
{
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);

    return [$code, is_array($decoded) ? $decoded : [], $raw];
}

// Mint an admin bearer token.
$superId = $pdo->query(
    "SELECT id FROM users WHERE 'super' = ANY (SELECT jsonb_array_elements_text(roles)) LIMIT 1"
)->fetchColumn();
if (!$superId) {
    fwrite(STDERR, "No super user in DB. Did the seed run?\n");
    exit(1);
}
$sessions = new AdminSessionRepository($pdo);
$adminToken = $sessions->create((string) $superId);

// Pick an unprovisioned station (or insert a throwaway one).
$stationId = (string) ($pdo->query(
    "SELECT id FROM stations WHERE user_id IS NULL ORDER BY created_at ASC LIMIT 1"
)->fetchColumn() ?: '');
$cleanupStation = false;
if ($stationId === '') {
    $regionId = $pdo->query("SELECT id FROM regions LIMIT 1")->fetchColumn();
    $slug = 'partner_e2e_' . bin2hex(random_bytes(3));
    $stmt = $pdo->prepare(
        "INSERT INTO stations (region_id, name, slug, station_code, city_name)
         VALUES (:r, 'Partner E2E FM', :slug, :code, 'Test')
         RETURNING id"
    );
    $stmt->execute(['r' => $regionId, 'slug' => $slug, 'code' => $slug]);
    $stationId = (string) $stmt->fetchColumn();
    $cleanupStation = true;
}

$partnerToken = null;
$partnerUserId = null;
$rotatedPassword = null;
$initialPassword = null;
$firstNewsToken = null;

try {
    // 1. Admin provisions the station.
    [$code, $body] = api('POST', "{$base}/stations/{$stationId}/provision", $adminToken);
    check($code === 201, "POST /stations/{id}/provision → 201 (got {$code})");
    $username = (string) ($body['result']['username'] ?? '');
    $initialPassword = (string) ($body['result']['one_time_password'] ?? '');
    $partnerUserId = (string) ($body['result']['user_id'] ?? '');
    check($username !== '', 'provision returns username');
    check(strlen($initialPassword) >= 16, 'one-shot password is at least 16 chars');
    check($partnerUserId !== '', 'provision returns user_id');

    // 2. Partner logs in (verifies bcrypt verification path through the real
    //    HTTP endpoint). The actual session token lives in an HttpOnly cookie,
    //    so the test mints a parallel session via the repository for
    //    Bearer-auth on subsequent calls.
    [$code, $body] = api('POST', "{$base}/auth/login", null, [
        'username' => $username,
        'password' => $initialPassword,
    ]);
    check($code === 200, "partner login (HTTP) → 200 (got {$code})");
    check(($body['result']['userId'] ?? '') === $partnerUserId, 'login returns the partner userId');
    $partnerToken = $sessions->create($partnerUserId);

    // 3. /portal/me — own corporate card.
    [$code, $body] = api('GET', "{$base}/portal/me", $partnerToken);
    check($code === 200, "GET /portal/me → 200 (got {$code})");
    check((string) ($body['result']['station_id'] ?? '') === $stationId, 'portal/me returns the bound station');

    // /portal/links — 8 signed URLs.
    [$code, $body] = api('GET', "{$base}/portal/links", $partnerToken);
    check($code === 200, "GET /portal/links → 200 (got {$code})");
    $links = $body['result']['links'] ?? [];
    check(count($links) === 8, 'partner gets 8 purpose-keyed links (got ' . count($links) . ')');
    $news = null;
    foreach ($links as $l) {
        if (($l['purpose'] ?? '') === 'news') {
            $news = $l;
            break;
        }
    }
    check($news !== null, 'links include a news entry');
    $firstNewsToken = (string) ($news['token'] ?? '');

    // /portal/feeds, /portal/media, /portal/activity — all 200.
    foreach (['feeds', 'media', 'activity'] as $endpoint) {
        [$code] = api('GET', "{$base}/portal/{$endpoint}", $partnerToken);
        check($code === 200, "GET /portal/{$endpoint} → 200 (got {$code})");
    }

    // 4. Tenant isolation — partner CANNOT reach admin endpoints.
    [$code] = api('GET', "{$base}/users", $partnerToken);
    check($code === 403, "partner GET /users → 403 (got {$code})");
    [$code] = api('GET', "{$base}/audit/logs", $partnerToken);
    check($code === 403, "partner GET /audit/logs → 403 (got {$code})");
    [$code] = api('POST', "{$base}/stations/{$stationId}/rotate-tokens", $partnerToken);
    check($code === 403, "partner POST rotate-tokens → 403 (got {$code})");

    // 5. Signed-URL feed reachable WITHOUT a session.
    $url = "{$base}/stream/radio/{$stationId}/{$firstNewsToken}/news.json";
    [$code] = api('GET', $url, null);
    check($code === 200 || $code === 404, "signed feed reachable without auth (got {$code})");
    // (404 only when there's no renderable media in the region; auth itself works.)

    // 6. Admin rotates tokens → old token now 403.
    [$code, $body] = api('POST', "{$base}/stations/{$stationId}/rotate-tokens", $adminToken);
    check($code === 200, "POST /stations/{id}/rotate-tokens → 200 (got {$code})");
    $newTokens = $body['result']['tokens'] ?? [];
    check(count($newTokens) === 8, 'rotate-tokens returns 8 fresh tokens');
    check(($newTokens['news'] ?? '') !== $firstNewsToken, 'news token actually changed');

    [$code] = api('GET', $url, null); // old URL
    check($code === 403, "old signed URL after rotation → 403 (got {$code})");

    // 7. Admin rotates the password; old credentials must stop working.
    [$code, $body] = api('POST', "{$base}/stations/{$stationId}/rotate-password", $adminToken);
    check($code === 200, "POST /stations/{id}/rotate-password → 200 (got {$code})");
    $rotatedPassword = (string) ($body['result']['one_time_password'] ?? '');
    check(strlen($rotatedPassword) >= 16, 'rotated one-shot password is at least 16 chars');
    check($rotatedPassword !== $initialPassword, 'rotated password differs');

    // Old password no longer logs in.
    [$code] = api('POST', "{$base}/auth/login", null, [
        'username' => $username,
        'password' => $initialPassword,
    ]);
    check($code === 401, "old password rejected after rotation → 401 (got {$code})");
    // New password works.
    [$code, $body] = api('POST', "{$base}/auth/login", null, [
        'username' => $username,
        'password' => $rotatedPassword,
    ]);
    check($code === 200 && ($body['result']['userId'] ?? '') === $partnerUserId,
        'rotated password logs in');

    // 8. Faz 16: Support module — partner opens a ticket, admin replies,
    //    partner sees the admin reply. Tenant isolation guaranteed.
    $newPartnerToken = $sessions->create($partnerUserId);
    [$code, $body] = api('POST', "{$base}/portal/support", $newPartnerToken, [
        'category' => 'technical',
        'subject' => 'E2E Test Talebi',
        'body' => 'Yayın linkim çalışmıyor.',
    ]);
    check($code === 201, "partner POST /portal/support → 201 (got {$code})");
    $ticketId = (string) ($body['result']['id'] ?? '');
    check($ticketId !== '', 'partner ticket created with id');

    [$code, $body] = api('GET', "{$base}/portal/support", $newPartnerToken);
    check($code === 200 && count($body['result']['tickets'] ?? []) >= 1,
        'partner sees own ticket in list');

    // Admin reads + replies.
    [$code, $body] = api('GET', "{$base}/support/tickets/{$ticketId}", $adminToken);
    check($code === 200, "admin GET /support/tickets/{id} → 200 (got {$code})");

    [$code] = api('POST', "{$base}/support/tickets/{$ticketId}/message", $adminToken, [
        'body' => 'Tokenları yeniledik, tekrar deneyin.',
    ]);
    check($code === 201, "admin reply → 201 (got {$code})");

    [$code, $body] = api('PATCH', "{$base}/support/tickets/{$ticketId}/status", $adminToken, [
        'status' => 'in_progress',
    ]);
    check($code === 200, "admin PATCH status → 200 (got {$code})");
    check(($body['result']['status'] ?? '') === 'in_progress', 'ticket status now in_progress');

    [$code, $body] = api('GET', "{$base}/portal/support/{$ticketId}", $newPartnerToken);
    check($code === 200, "partner GET own ticket → 200 (got {$code})");
    check(count($body['result']['messages'] ?? []) >= 1, 'partner sees admin reply');

    // Cross-tenant isolation: a fresh second station + user cannot read
    // the first partner's ticket even by guessing its UUID.
    $rid2 = $pdo->query("SELECT id FROM regions LIMIT 1")->fetchColumn();
    $slug2 = 'partner_e2e_iso_' . bin2hex(random_bytes(3));
    $pdo->prepare(
        "INSERT INTO stations (region_id, name, slug, station_code, city_name)
         VALUES (:r, 'Iso E2E FM', :s, :c, 'Iso')"
    )->execute(['r' => $rid2, 's' => $slug2, 'c' => $slug2]);
    $isoStation = (string) $pdo->query("SELECT id FROM stations WHERE slug = '{$slug2}'")->fetchColumn();
    [$code, $body] = api('POST', "{$base}/stations/{$isoStation}/provision", $adminToken);
    $isoUserId = (string) ($body['result']['user_id'] ?? '');
    $isoToken = $sessions->create($isoUserId);
    [$code] = api('GET', "{$base}/portal/support/{$ticketId}", $isoToken);
    check($code === 404, "other tenant cannot read foreign ticket → 404 (got {$code})");

    // Cleanup the iso station.
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :u')->execute(['u' => $isoUserId]);
    $pdo->prepare('UPDATE stations SET user_id = NULL WHERE id = :s')->execute(['s' => $isoStation]);
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $isoUserId]);
    $pdo->prepare('DELETE FROM stations WHERE id = :s')->execute(['s' => $isoStation]);
} finally {
    // Cleanup.
    if ($partnerUserId !== null && $partnerUserId !== '') {
        $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :u')->execute(['u' => $partnerUserId]);
    }
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = :u')->execute(['u' => $superId]);
    if ($stationId !== '') {
        $pdo->prepare('DELETE FROM station_stream_tokens WHERE station_id = :s')->execute(['s' => $stationId]);
        $pdo->prepare('UPDATE stations SET user_id = NULL WHERE id = :s')->execute(['s' => $stationId]);
        if ($partnerUserId !== null && $partnerUserId !== '') {
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $partnerUserId]);
        }
        if ($cleanupStation) {
            $pdo->prepare('DELETE FROM stations WHERE id = :s')->execute(['s' => $stationId]);
        }
    }
}

echo "\nPartner E2E: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
