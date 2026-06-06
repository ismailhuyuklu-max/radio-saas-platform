<?php

declare(strict_types=1);

/**
 * Mint admin + partner session tokens for the documentation screenshot
 * pass. Writes both tokens to stdout as JSON so the Playwright capture
 * script can set them as cookies. Idempotent: cleans up old "doc_*"
 * artefacts and re-creates a single fresh partner.
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AdminSessionRepository;
use RadioSaaS\Service\RadioCredentialService;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Service\StreamTokenService;
use RadioSaaS\Repository\StreamTokenRepository;

$pdo = PdoFactory::fromEnv();
$sessions = new AdminSessionRepository($pdo);

// --- Admin session -------------------------------------------------------
$adminId = (string) $pdo->query(
    "SELECT id FROM users WHERE 'super' = ANY (SELECT jsonb_array_elements_text(roles)) LIMIT 1"
)->fetchColumn();
if ($adminId === '') {
    fwrite(STDERR, "No super admin found\n");
    exit(1);
}
$adminSession = $sessions->create($adminId);

// --- Doc partner: clean up previous doc_partner rows, create fresh ------
$pdo->prepare("DELETE FROM admin_sessions WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'doc_%')")->execute();
$pdo->prepare("DELETE FROM station_stream_tokens WHERE station_id IN (SELECT id FROM stations WHERE slug LIKE 'doc_%')")->execute();
$pdo->prepare("UPDATE stations SET user_id = NULL WHERE slug LIKE 'doc_%'")->execute();
$pdo->prepare("DELETE FROM users WHERE username LIKE 'doc_%'")->execute();
$pdo->prepare("DELETE FROM stations WHERE slug LIKE 'doc_%'")->execute();

$regionId = $pdo->query("SELECT id FROM regions WHERE code = 'marmara' LIMIT 1")->fetchColumn();
if (!$regionId) {
    $regionId = $pdo->query('SELECT id FROM regions LIMIT 1')->fetchColumn();
}
$stationRepo = new StationRepository($pdo);
$stationId = $stationRepo->insert([
    'region_id' => $regionId,
    'name' => 'Aircast Demo FM',
    'slug' => 'doc_demo_fm',
    'station_code' => 'doc_demo_fm',
    'city_name' => 'İstanbul',
    'status' => 'active',
    'is_active' => true,
]);
// Enrich the corporate card so screenshots show something.
$stationRepo->updateProfile($stationId, [
    'frequency' => '101.5 FM',
    'company_name' => 'Aircast Yayıncılık A.Ş.',
    'contact_name' => 'Demo Yetkili',
    'contact_phone' => '+90 312 000 00 00',
    'contact_email' => 'demo@aircast.fm',
    'website' => 'https://aircast.fm',
]);

$creds = new RadioCredentialService(new UserRepository($pdo), $stationRepo);
$prov = $creds->provision($stationId);
$partnerUserId = (string) ($prov['user']['id'] ?? '');
$partnerSession = $sessions->create($partnerUserId);

// Issue stream tokens too.
$streamSvc = new StreamTokenService(new StreamTokenRepository($pdo));
$streamSvc->rotate($stationId);

echo json_encode([
    'admin_token' => $adminSession,
    'admin_user_id' => $adminId,
    'partner_token' => $partnerSession,
    'partner_user_id' => $partnerUserId,
    'partner_username' => $prov['username'],
    'partner_one_time_password' => $prov['password'],
    'partner_station_id' => $stationId,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
