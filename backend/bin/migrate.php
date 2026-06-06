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

/**
 * Faz 2 — traffic data model: provinces (81 il), province/campaign-keyed plans,
 * and radio groups (all idempotent).
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS provinces (
        name varchar(64) PRIMARY KEY,
        region_code varchar(32) NOT NULL,
        plate integer NULL
    )"
);

$provinceData = [
    // [name, region_code, plate]
    ['İstanbul', 'marmara', 34], ['Balıkesir', 'marmara', 10], ['Bursa', 'marmara', 16],
    ['Çanakkale', 'marmara', 17], ['Edirne', 'marmara', 22], ['Kırklareli', 'marmara', 39],
    ['Kocaeli', 'marmara', 41], ['Sakarya', 'marmara', 54], ['Tekirdağ', 'marmara', 59],
    ['Yalova', 'marmara', 77], ['Bilecik', 'marmara', 11],
    ['İzmir', 'ege', 35], ['Aydın', 'ege', 9], ['Denizli', 'ege', 20], ['Muğla', 'ege', 48],
    ['Manisa', 'ege', 45], ['Afyonkarahisar', 'ege', 3], ['Kütahya', 'ege', 43], ['Uşak', 'ege', 64],
    ['Antalya', 'akdeniz', 7], ['Adana', 'akdeniz', 1], ['Mersin', 'akdeniz', 33],
    ['Hatay', 'akdeniz', 31], ['Isparta', 'akdeniz', 32], ['Burdur', 'akdeniz', 15],
    ['Osmaniye', 'akdeniz', 80], ['Kahramanmaraş', 'akdeniz', 46],
    ['Ankara', 'ic-anadolu', 6], ['Konya', 'ic-anadolu', 42], ['Kayseri', 'ic-anadolu', 38],
    ['Eskişehir', 'ic-anadolu', 26], ['Sivas', 'ic-anadolu', 58], ['Yozgat', 'ic-anadolu', 66],
    ['Aksaray', 'ic-anadolu', 68], ['Karaman', 'ic-anadolu', 70], ['Kırıkkale', 'ic-anadolu', 71],
    ['Kırşehir', 'ic-anadolu', 40], ['Nevşehir', 'ic-anadolu', 50], ['Niğde', 'ic-anadolu', 51],
    ['Çankırı', 'ic-anadolu', 18],
    ['Samsun', 'karadeniz', 55], ['Trabzon', 'karadeniz', 61], ['Ordu', 'karadeniz', 52],
    ['Giresun', 'karadeniz', 28], ['Rize', 'karadeniz', 53], ['Artvin', 'karadeniz', 8],
    ['Gümüşhane', 'karadeniz', 29], ['Bayburt', 'karadeniz', 69], ['Bartın', 'karadeniz', 74],
    ['Bolu', 'karadeniz', 14], ['Çorum', 'karadeniz', 19], ['Düzce', 'karadeniz', 81],
    ['Karabük', 'karadeniz', 78], ['Kastamonu', 'karadeniz', 37], ['Sinop', 'karadeniz', 57],
    ['Tokat', 'karadeniz', 60], ['Amasya', 'karadeniz', 5], ['Zonguldak', 'karadeniz', 67],
    ['Erzurum', 'dogu-anadolu', 25], ['Erzincan', 'dogu-anadolu', 24], ['Ağrı', 'dogu-anadolu', 4],
    ['Ardahan', 'dogu-anadolu', 75], ['Bingöl', 'dogu-anadolu', 12], ['Bitlis', 'dogu-anadolu', 13],
    ['Elazığ', 'dogu-anadolu', 23], ['Hakkâri', 'dogu-anadolu', 30], ['Iğdır', 'dogu-anadolu', 76],
    ['Kars', 'dogu-anadolu', 36], ['Malatya', 'dogu-anadolu', 44], ['Muş', 'dogu-anadolu', 49],
    ['Tunceli', 'dogu-anadolu', 62], ['Van', 'dogu-anadolu', 65],
    ['Gaziantep', 'guneydogu-anadolu', 27], ['Diyarbakır', 'guneydogu-anadolu', 21],
    ['Şanlıurfa', 'guneydogu-anadolu', 63], ['Mardin', 'guneydogu-anadolu', 47],
    ['Batman', 'guneydogu-anadolu', 72], ['Siirt', 'guneydogu-anadolu', 56],
    ['Şırnak', 'guneydogu-anadolu', 73], ['Adıyaman', 'guneydogu-anadolu', 2],
    ['Kilis', 'guneydogu-anadolu', 79],
];
$provinceStmt = $pdo->prepare(
    'INSERT INTO provinces (name, region_code, plate) VALUES (:n, :r, :p)
     ON CONFLICT (name) DO UPDATE SET region_code = EXCLUDED.region_code, plate = EXCLUDED.plate'
);
foreach ($provinceData as [$pn, $pr, $pp]) {
    $provinceStmt->execute(['n' => $pn, 'r' => $pr, 'p' => $pp]);
}

// Radio groups (Radyo Grubu targeting).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS station_groups (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        name varchar(128) NOT NULL UNIQUE,
        description varchar(255) NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS group_id uuid NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_group ON stations (group_id)');

/**
 * Faz 12 — Partner Radio Portal: bind a station to its dedicated user,
 * enrich the corporate profile card (logo / frequency / company / contact),
 * and track last broadcast time for the partner-portal dashboard.
 */
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS user_id uuid NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS logo_url varchar(512) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS frequency varchar(32) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS company_name varchar(255) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS contact_name varchar(128) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS contact_phone varchar(64) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS contact_email varchar(128) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS website varchar(255) NULL');
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS last_broadcast_at timestamptz NULL');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_stations_user_id ON stations (user_id) WHERE user_id IS NOT NULL');

// Bind the partner user back to its station (a station_user role only ever
// operates inside that one station's tenant scope).
$pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS station_id uuid NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_station ON users (station_id) WHERE station_id IS NOT NULL');

/**
 * Faz 13 — Signed-URL style stream tokens. Each station has 8 purpose-keyed
 * tokens (news/sports/economy/weather/sponsor/ad/special/emergency).
 * Rotation revokes the old row (revoked_at) and inserts a new one so any
 * cached partner link stops working immediately.
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS station_stream_tokens (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        station_id uuid NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
        purpose varchar(32) NOT NULL,
        token varchar(96) NOT NULL UNIQUE,
        ip_restriction varchar(64) NULL,
        domain_restriction varchar(255) NULL,
        expires_at timestamptz NULL,
        revoked_at timestamptz NULL,
        last_used_at timestamptz NULL,
        use_count integer NOT NULL DEFAULT 0,
        created_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stream_tokens_station ON station_stream_tokens (station_id, purpose) WHERE revoked_at IS NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_stream_tokens_token ON station_stream_tokens (token) WHERE revoked_at IS NULL');

/**
 * Faz 16 — Support tickets per partner radio. Categories enumerated in PHP
 * (technical / broadcast / ad / news / general). status: open / in_progress
 * / resolved / closed. Threaded follow-ups in support_ticket_messages.
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS support_tickets (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        station_id uuid NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
        category varchar(32) NOT NULL,
        subject varchar(255) NOT NULL,
        body text NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'open',
        created_by uuid NULL,
        assigned_to uuid NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_station ON support_tickets (station_id, status, created_at DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_status ON support_tickets (status, created_at DESC)');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS support_ticket_messages (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        ticket_id uuid NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
        author_type varchar(16) NOT NULL,
        author_id uuid NULL,
        body text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_ticket_msgs_ticket ON support_ticket_messages (ticket_id, created_at)');

/**
 * Faz 19 — Programmatic API keys per partner station. The master prompt's
 * KAYIT SİSTEMİ lists "API Anahtarı" + "Güvenlik Tokenı" as separate items;
 * stream tokens cover signed-URL feeds, this table covers /api/v1/* calls
 * from a partner's own server-side integration. Hashed at rest (sha256).
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS partner_api_keys (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        station_id uuid NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
        name varchar(120) NOT NULL,
        key_hash varchar(128) NOT NULL UNIQUE,
        key_prefix varchar(16) NOT NULL,
        scopes jsonb NOT NULL DEFAULT '[]'::jsonb,
        last_used_at timestamptz NULL,
        last_used_ip varchar(64) NULL,
        revoked_at timestamptz NULL,
        created_by uuid NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_keys_station ON partner_api_keys (station_id, revoked_at)');

/**
 * Faz 21 — Audit log: master prompt wants "kim, ne zaman, hangi IP ile,
 * hangi işlemi". The ip_address column closes that gap. Idempotent ADD.
 */
$pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address varchar(64) NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_ip ON audit_logs (ip_address, created_at DESC)');

/**
 * Faz 22 — Ulusal yetkili radyolar. Master prompt: "Ulusal yetkili radyolar
 * tüm Türkiye içeriklerini görebilir". Default false: bölge kilidi devam.
 */
$pdo->exec('ALTER TABLE stations ADD COLUMN IF NOT EXISTS national_access boolean NOT NULL DEFAULT false');

/**
 * Faz 25 — JWT access tokens + opaque refresh tokens. Hashed at rest like the
 * API keys. Master prompt: 'JWT, Refresh Token' güvenlik gereksinimi.
 */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash varchar(128) NOT NULL UNIQUE,
        expires_at timestamptz NOT NULL,
        revoked_at timestamptz NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )"
);
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_refresh_user ON auth_refresh_tokens (user_id, revoked_at)');

// Province- and campaign-keyed plans.
$pdo->exec("ALTER TABLE content_plans ADD COLUMN IF NOT EXISTS province varchar(64) NULL");
$pdo->exec('ALTER TABLE content_plans ADD COLUMN IF NOT EXISTS campaign_id uuid NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_plans_province ON content_plans (region_id, province, plan_date, slot_time)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_plans_campaign ON content_plans (campaign_id)');

echo "Migrations complete.\n";
