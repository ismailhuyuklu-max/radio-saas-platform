-- =============================================================================
-- Migration 002: Sync Clients table
-- =============================================================================
-- AdCast Pro Windows Sync Client (sync-client/) per-machine durum izleme.
-- Bir kullanıcı birden çok makinede oturum açabilir (örn. ana studio PC +
-- yedek PC); her makine ayrı sync_client kaydıdır.
--
-- Admin panelde her radyo için: online/offline durumu, son bağlantı, indirilen
-- son dosya, eksik dosya, hata, client_version, IP, Windows sürümü görünür.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sync_clients (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    machine_id VARCHAR(64) NOT NULL,           -- client tarafından üretilen stable UUID (HKLM\Software\AdCastPro\MachineId)
    client_version VARCHAR(32) NOT NULL DEFAULT '0.0.0',
    os VARCHAR(64) DEFAULT 'Unknown',          -- "Windows 11 Pro 24H2"
    user_agent TEXT DEFAULT '',                -- "AdCastPro.SyncClient/1.0.0 (.NET 8.0; Windows 11)"
    last_seen_ip INET,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    disk_free_gb INTEGER DEFAULT 0,            -- son heartbeat'teki kullanılabilir disk
    last_sync_at TIMESTAMPTZ,                  -- son başarılı manifest sync
    last_sync_file_count INTEGER DEFAULT 0,
    last_error TEXT,                           -- son hata mesajı (null = sağlıklı)
    last_error_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, machine_id)               -- aynı user+machine = tek kayıt (upsert)
);

CREATE INDEX IF NOT EXISTS idx_sync_clients_user ON sync_clients(user_id);
CREATE INDEX IF NOT EXISTS idx_sync_clients_last_seen ON sync_clients(last_seen_at DESC);
-- Admin panelde "offline" filtreleme için (last_seen_at < NOW() - INTERVAL '5 minutes')

-- Online/offline view: NOC ekranı için kolay query
CREATE OR REPLACE VIEW v_sync_client_status AS
SELECT
    sc.id,
    sc.user_id,
    sc.machine_id,
    sc.client_version,
    sc.os,
    sc.last_seen_ip,
    sc.last_seen_at,
    sc.last_sync_at,
    sc.disk_free_gb,
    sc.last_error,
    sc.last_error_at,
    CASE
        WHEN sc.last_seen_at > NOW() - INTERVAL '2 minutes' THEN 'online'
        WHEN sc.last_seen_at > NOW() - INTERVAL '10 minutes' THEN 'stale'
        ELSE 'offline'
    END AS connection_status,
    au.username,
    au.radio_id,
    s.name AS radio_name,
    s.region AS radio_region,
    s.province AS radio_province
FROM sync_clients sc
JOIN admin_users au ON au.id = sc.user_id
LEFT JOIN stations s ON s.id = au.radio_id;

-- =============================================================================
-- Sync Activity Log — her dosya download'unun audit trail'i
-- =============================================================================
-- audit_logs zaten generic event log ama sync trafiği çok yoğun olabilir
-- (her radyo × her dosya × her saat = 500 × 20 × 24 = 240K event/gün).
-- Ayrı tabloya yazıp 30 gün retention ile temiz tutuyoruz.
CREATE TABLE IF NOT EXISTS sync_activity (
    id BIGSERIAL PRIMARY KEY,
    sync_client_id BIGINT NOT NULL REFERENCES sync_clients(id) ON DELETE CASCADE,
    file_id VARCHAR(64) NOT NULL,              -- media_contents.id veya content_plans.id
    file_type VARCHAR(32) NOT NULL,            -- 'news' / 'ad' / 'media_plan' / 'sponsor'
    event VARCHAR(32) NOT NULL,                -- 'started' / 'completed' / 'failed' / 'checksum_failed'
    bytes_downloaded BIGINT DEFAULT 0,
    duration_ms INTEGER DEFAULT 0,
    checksum_ok BOOLEAN,                       -- null = checksum henüz yapılmadı
    error_message TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sync_activity_client_created
    ON sync_activity(sync_client_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sync_activity_file
    ON sync_activity(file_id, created_at DESC);

-- Retention: 30 gün — eski sync activity audit_retention cron tarafından temizlenir.
-- (bin/audit-retention.php aynı pattern ile bu tabloya da DELETE atar)
