<?php

declare(strict_types=1);

/**
 * Bulk-seed a load-test corpus matching the master prompt's stated scale:
 *   500+ radyo, 5000+ kullanıcı, 100.000+ dosya.
 *
 * Run inside the app network:
 *   docker compose exec php-fpm php bin/seed-load.php
 *
 * Idempotent: re-runs top up the targets without duplicating. All rows are
 * prefixed "load_" so a single DELETE can clear them.
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;

$pdo = PdoFactory::fromEnv();

$stationTarget = (int) (getenv('SEED_STATIONS') ?: 500);
$userTarget = (int) (getenv('SEED_USERS') ?: 5_000);
$mediaTarget = (int) (getenv('SEED_MEDIA') ?: 100_000);

$regions = $pdo->query('SELECT id, code FROM regions')->fetchAll(PDO::FETCH_ASSOC);
if (count($regions) === 0) {
    fwrite(STDERR, "No regions; seed default first.\n");
    exit(1);
}

// --- Stations -------------------------------------------------------------
$stationCount = (int) $pdo->query("SELECT count(*) FROM stations WHERE slug LIKE 'load_%'")
    ->fetchColumn();
$insertStation = $pdo->prepare(
    "INSERT INTO stations (region_id, name, slug, station_code, city_name, national_access)
     VALUES (:r, :n, :s, :c, :city, false)
     ON CONFLICT (region_id, slug) DO NOTHING"
);
for ($i = $stationCount; $i < $stationTarget; $i++) {
    $region = $regions[$i % count($regions)];
    $slug = 'load_st_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $insertStation->execute([
        'r' => $region['id'],
        'n' => 'Load FM ' . $i,
        's' => $slug,
        'c' => $slug,
        'city' => 'TestCity' . ($i % 81),
    ]);
}
echo "Stations: target {$stationTarget}\n";

// --- Users ---------------------------------------------------------------
$userCount = (int) $pdo->query("SELECT count(*) FROM users WHERE username LIKE 'load_%'")
    ->fetchColumn();
$insertUser = $pdo->prepare(
    "INSERT INTO users (username, password_hash, real_name, roles, is_active)
     VALUES (:u, :h, :r, CAST(:roles AS jsonb), true)
     ON CONFLICT (username) DO NOTHING"
);
// One bcrypt is enough for all load users; we never verify().
$throwawayHash = password_hash('load-test-' . bin2hex(random_bytes(4)), PASSWORD_BCRYPT);
$rolesJson = json_encode(['viewer']);
for ($i = $userCount; $i < $userTarget; $i++) {
    $insertUser->execute([
        'u' => 'load_user_' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        'h' => $throwawayHash,
        'r' => 'Load User ' . $i,
        'roles' => $rolesJson,
    ]);
}
echo "Users: target {$userTarget}\n";

// --- media_contents -----------------------------------------------------
$mediaCount = (int) $pdo->query("SELECT count(*) FROM media_contents WHERE title LIKE 'load_%'")
    ->fetchColumn();
$insertMedia = $pdo->prepare(
    "INSERT INTO media_contents
        (region_id, part_code, title, content_kind, source_bucket, source_key, source_mime, checksum_sha256, render_state, published_at)
     VALUES (:r, :p, :t, :p, 'load-bucket', :k, 'audio/mpeg', :sha, 'rendered', now())"
);
$parts = ['news', 'sports', 'economy', 'weather'];
$chunk = 500;
$toInsert = max(0, $mediaTarget - $mediaCount);
$inserted = 0;
$start = microtime(true);
while ($inserted < $toInsert) {
    $pdo->beginTransaction();
    for ($i = 0; $i < $chunk && $inserted < $toInsert; $i++, $inserted++) {
        $region = $regions[($mediaCount + $inserted) % count($regions)];
        $part = $parts[($mediaCount + $inserted) % count($parts)];
        $key = 'load/' . substr($region['code'], 0, 4) . '/' . $part . '/' . ($mediaCount + $inserted) . '.mp3';
        $insertMedia->execute([
            'r' => $region['id'],
            'p' => $part,
            't' => 'load_' . ($mediaCount + $inserted),
            'k' => $key,
            'sha' => hash('sha256', $key),
        ]);
    }
    $pdo->commit();
    if ($inserted % 5_000 === 0) {
        $secs = round(microtime(true) - $start, 1);
        echo "  inserted {$inserted}/{$toInsert} in {$secs}s\n";
    }
}
echo "Media: target {$mediaTarget}\n";

echo "Seed-load complete.\n";
