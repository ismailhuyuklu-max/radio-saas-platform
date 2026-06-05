<?php

declare(strict_types=1);

use RadioSaaS\Infrastructure\PdoFactory;

require __DIR__ . '/../vendor/autoload.php';

$migrationsPath = getenv('MIGRATIONS_PATH') ?: '/var/migrations';
$pdo = PdoFactory::fromEnv();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version varchar(191) PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
    )'
);

$files = glob(rtrim($migrationsPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
if ($files === false) {
    fwrite(STDERR, "Unable to read migrations path: {$migrationsPath}\n");
    exit(1);
}

sort($files, SORT_STRING);

foreach ($files as $file) {
    $version = basename($file);

    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
    $check->execute(['version' => $version]);
    if ($check->fetchColumn() !== false) {
        echo "[skip] {$version}\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "[error] empty migration file: {$version}\n");
        exit(1);
    }

    $pdo->beginTransaction();

    try {
        $pdo->exec($sql);

        $mark = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $mark->execute(['version' => $version]);

        $pdo->commit();
        echo "[applied] {$version}\n";
    } catch (Throwable $throwable) {
        $pdo->rollBack();
        fwrite(STDERR, "[failed] {$version}: {$throwable->getMessage()}\n");
        exit(1);
    }
}

/**
 * Create a minimal admin users table and seed the default credentials.
 * This keeps the auth flow idempotent during local bootstrap and first-run tests.
 */
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        username varchar(64) NOT NULL UNIQUE,
        password_hash varchar(255) NOT NULL,
        real_name varchar(128) NOT NULL,
        roles jsonb NOT NULL DEFAULT \'["super"]\'::jsonb,
        is_active boolean NOT NULL DEFAULT true,
        last_login_at timestamptz NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS admin_sessions (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash char(64) NOT NULL UNIQUE,
        expires_at timestamptz NOT NULL,
        revoked_at timestamptz NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )'
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_sessions_active ON admin_sessions (token_hash, expires_at, revoked_at)');

// Faz 7-MFA — TOTP two-factor columns (idempotent).
$pdo->exec(
    "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS mfa_secret varchar(64) NULL,
        ADD COLUMN IF NOT EXISTS mfa_enabled boolean NOT NULL DEFAULT false,
        ADD COLUMN IF NOT EXISTS mfa_recovery_codes jsonb NOT NULL DEFAULT '[]'::jsonb"
);

// Security — login brute-force throttle (idempotent).
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS login_throttle (
        username varchar(64) PRIMARY KEY,
        fail_count integer NOT NULL DEFAULT 0,
        locked_until timestamptz NULL,
        updated_at timestamptz NOT NULL DEFAULT now()
    )'
);

$appEnv = getenv('APP_ENV') ?: 'local';
$defaultUsername = getenv('ADMIN_USERNAME') ?: 'admin';
$defaultPassword = getenv('ADMIN_PASSWORD') ?: '123456';
$defaultRealName = getenv('ADMIN_REAL_NAME') ?: 'İsmail Hüyüklü';
$defaultRoles = json_encode(['super'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($appEnv === 'production' && $defaultPassword === '123456') {
    fwrite(STDERR, "[GUVENLIK HATASI] Production ortaminda varsayilan/bos admin sifresi (123456). Migrasyon durduruldu; ADMIN_PASSWORD ortam degiskenini guclu bir deger ile ayarlayin.\n");
    exit(1);
}

$passwordHash = password_hash($defaultPassword, PASSWORD_BCRYPT);

$seedUser = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
$seedUser->execute(['username' => $defaultUsername]);

if ($seedUser->fetchColumn() === false) {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, real_name, roles, is_active)
         VALUES (:username, :password_hash, :real_name, CAST(:roles AS jsonb), true)'
    );
    $insertUser->execute([
        'username' => $defaultUsername,
        'password_hash' => $passwordHash,
        'real_name' => $defaultRealName,
        'roles' => $defaultRoles,
    ]);
    echo "[applied] seeded default admin user\n";
} else {
    echo "[skip] default admin user already exists\n";
}

/**
 * Extend the sponsors and stations tables without breaking existing rows.
 */
$pdo->exec(
    "ALTER TABLE sponsors_ads
        ADD COLUMN IF NOT EXISTS placement_type varchar(16) NOT NULL DEFAULT 'intro',
        ADD COLUMN IF NOT EXISTS is_global boolean NOT NULL DEFAULT false,
        ADD COLUMN IF NOT EXISTS content_type varchar(32) NOT NULL DEFAULT 'news'"
);

$pdo->exec(
    "ALTER TABLE stations
        ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true,
        ADD COLUMN IF NOT EXISTS city_name varchar(128) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS stream_token varchar(255) NULL"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS content_plans (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        region_id uuid NOT NULL REFERENCES regions(id) ON DELETE CASCADE,
        station_id uuid NULL REFERENCES stations(id) ON DELETE SET NULL,
        part_code varchar(32) NOT NULL,
        slot_time time NOT NULL,
        plan_date date NOT NULL,
        content_title varchar(255) NOT NULL,
        content_kind varchar(32) NOT NULL DEFAULT \'news\',
        status varchar(32) NOT NULL DEFAULT \'draft\',
        is_global boolean NOT NULL DEFAULT false,
        target_regions jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        target_parts jsonb NOT NULL DEFAULT \'[]\'::jsonb,
        notes text NULL,
        created_by varchar(128) NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS audit_logs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        actor_username varchar(128) NOT NULL,
        action varchar(128) NOT NULL,
        entity_type varchar(64) NOT NULL,
        entity_id varchar(64) NULL,
        payload jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        ip_address inet NULL,
        user_agent text NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )'
);

$pdo->exec(
    "UPDATE sponsors_ads
     SET placement_type = CASE
            WHEN COALESCE(placement_type, '') = '' THEN
                CASE
                    WHEN placement = 'post_roll' THEN 'outro'
                    ELSE 'intro'
                END
            ELSE placement_type
        END,
        is_global = COALESCE(is_global, false),
        content_type = COALESCE(NULLIF(content_type, ''), part_code, 'news')"
);

$pdo->exec(
    "UPDATE stations
     SET city_name = COALESCE(NULLIF(city_name, ''), name),
         is_active = COALESCE(is_active, status = 'active')"
);

$pdo->exec('CREATE INDEX IF NOT EXISTS idx_sponsors_ads_region_content_placement ON sponsors_ads (region_id, content_type, placement_type, is_global, priority)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_sponsors_ads_global_active ON sponsors_ads (is_global, is_active, content_type)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_region_active ON stations (region_id, is_active, status)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_city_name ON stations (city_name)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_stream_token ON stations (stream_token)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_plans_region_date_slot ON content_plans (region_id, plan_date, slot_time)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_plans_station_date ON content_plans (station_id, plan_date)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs (created_at DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs (entity_type, entity_id)');

/**
 * Faz 4 — Ad Traffic / revenue. Campaigns drive CPM/CPP/flat revenue
 * projections (impressions are estimated from planned spots × regional reach).
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ad_campaigns (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        advertiser_name varchar(160) NOT NULL,
        sponsor_ad_id uuid NULL,
        pricing_model varchar(16) NOT NULL DEFAULT 'cpm',
        rate numeric(12,2) NOT NULL DEFAULT 0,
        budget numeric(14,2) NOT NULL DEFAULT 0,
        currency varchar(8) NOT NULL DEFAULT 'TRY',
        spots_per_day integer NOT NULL DEFAULT 1,
        target_regions jsonb NOT NULL DEFAULT '[]'::jsonb,
        target_parts jsonb NOT NULL DEFAULT '[]'::jsonb,
        starts_at date NOT NULL,
        ends_at date NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'active',
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_ad_campaigns_status_dates ON ad_campaigns (status, starts_at, ends_at)');

// Faz 4 (realism) — recorded ad airings. When present, revenue is computed from
// actual airings instead of the time-based projection.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ad_airings (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        campaign_id uuid NOT NULL REFERENCES ad_campaigns(id) ON DELETE CASCADE,
        region_code varchar(32) NOT NULL,
        part_code varchar(32) NOT NULL DEFAULT 'news',
        impressions integer NOT NULL DEFAULT 0,
        aired_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_ad_airings_campaign ON ad_airings (campaign_id)');

// Slot-aware media: bind a news/media item to a 2-hour broadcast slot so each
// slot serves its own audio at its time (idempotent).
$pdo->exec('ALTER TABLE media_contents ADD COLUMN IF NOT EXISTS slot_time time NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_region_part_slot ON media_contents (region_id, part_code, slot_time, render_state)');

echo "Migrations complete.\n";
