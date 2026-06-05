CREATE EXTENSION IF NOT EXISTS pgcrypto;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'render_placement') THEN
        CREATE TYPE render_placement AS ENUM ('pre_roll', 'post_roll');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS regions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code varchar(32) NOT NULL UNIQUE,
    name varchar(64) NOT NULL UNIQUE,
    sort_order smallint NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS stations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    region_id uuid NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
    name varchar(128) NOT NULL,
    slug varchar(128) NOT NULL,
    station_code varchar(64) NOT NULL,
    stream_kind varchar(24) NOT NULL DEFAULT 'radio',
    status varchar(24) NOT NULL DEFAULT 'active',
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT stations_slug_unique UNIQUE (region_id, slug),
    CONSTRAINT stations_code_unique UNIQUE (station_code),
    CONSTRAINT stations_status_check CHECK (status IN ('active', 'paused', 'archived')),
    CONSTRAINT stations_stream_kind_check CHECK (stream_kind IN ('radio', 'tv', 'hybrid'))
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    station_id uuid NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
    token_hash char(64) NOT NULL UNIQUE,
    token_prefix varchar(12) NOT NULL,
    scopes text[] NOT NULL DEFAULT ARRAY['feeds:read'],
    expires_at timestamptz NULL,
    last_used_at timestamptz NULL,
    revoked_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT api_tokens_valid_hash CHECK (token_hash ~ '^[0-9a-f]{64}$')
);

CREATE INDEX IF NOT EXISTS idx_api_tokens_station ON api_tokens(station_id);
CREATE INDEX IF NOT EXISTS idx_api_tokens_active ON api_tokens(revoked_at, expires_at);

CREATE TABLE IF NOT EXISTS media_contents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    region_id uuid NOT NULL REFERENCES regions(id) ON DELETE RESTRICT,
    station_id uuid NULL REFERENCES stations(id) ON DELETE SET NULL,
    part_code varchar(32) NOT NULL,
    title varchar(255) NOT NULL,
    content_kind varchar(32) NOT NULL,
    source_bucket varchar(64) NOT NULL,
    source_key varchar(512) NOT NULL,
    source_mime varchar(128) NOT NULL,
    source_duration_ms integer NOT NULL DEFAULT 0,
    checksum_sha256 char(64) NOT NULL,
    render_state varchar(24) NOT NULL DEFAULT 'queued',
    rendered_bucket varchar(64) NULL,
    rendered_key varchar(512) NULL,
    rendered_generated_at timestamptz NULL,
    rendered_checksum_sha256 char(64) NULL,
    published_at timestamptz NULL,
    effective_from timestamptz NOT NULL DEFAULT now(),
    effective_until timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT media_contents_part_check CHECK (part_code IN ('news', 'sports', 'economy', 'weather', 'road')),
    CONSTRAINT media_contents_kind_check CHECK (content_kind IN ('news', 'sports', 'economy', 'weather', 'road')),
    CONSTRAINT media_contents_render_state_check CHECK (render_state IN ('queued', 'rendering', 'rendered', 'failed', 'raw'))
);

CREATE INDEX IF NOT EXISTS idx_media_region_part_state ON media_contents(region_id, part_code, render_state, effective_from DESC);
CREATE INDEX IF NOT EXISTS idx_media_station_part ON media_contents(station_id, part_code);
CREATE INDEX IF NOT EXISTS idx_media_checksum ON media_contents(checksum_sha256);

CREATE TABLE IF NOT EXISTS sponsors_ads (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    region_id uuid NOT NULL REFERENCES regions(id) ON DELETE CASCADE,
    part_code varchar(32) NOT NULL,
    placement render_placement NOT NULL,
    sponsor_name varchar(255) NOT NULL,
    asset_bucket varchar(64) NOT NULL,
    asset_key varchar(512) NOT NULL,
    asset_mime varchar(128) NOT NULL,
    asset_duration_ms integer NOT NULL DEFAULT 0,
    priority integer NOT NULL DEFAULT 100,
    is_active boolean NOT NULL DEFAULT true,
    starts_at timestamptz NULL,
    ends_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT sponsors_ads_part_check CHECK (part_code IN ('news', 'sports', 'economy', 'weather', 'road'))
);

CREATE INDEX IF NOT EXISTS idx_sponsors_region_part_active ON sponsors_ads(region_id, part_code, is_active, placement, priority);
CREATE INDEX IF NOT EXISTS idx_sponsors_period ON sponsors_ads(starts_at, ends_at);

CREATE TABLE IF NOT EXISTS media_jobs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    job_type varchar(64) NOT NULL,
    media_content_id uuid NOT NULL REFERENCES media_contents(id) ON DELETE CASCADE,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    status varchar(24) NOT NULL DEFAULT 'pending',
    attempts integer NOT NULL DEFAULT 0,
    available_at timestamptz NOT NULL DEFAULT now(),
    locked_at timestamptz NULL,
    last_error text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT media_jobs_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
);

CREATE INDEX IF NOT EXISTS idx_media_jobs_ready ON media_jobs(status, available_at, created_at);
CREATE INDEX IF NOT EXISTS idx_media_jobs_content ON media_jobs(media_content_id, job_type);

INSERT INTO regions (code, name, sort_order)
VALUES
    ('marmara', 'Marmara', 1),
    ('ege', 'Ege', 2),
    ('akdeniz', 'Akdeniz', 3),
    ('karadeniz', 'Karadeniz', 4),
    ('ic-anadolu', 'İç Anadolu', 5),
    ('dogu-anadolu', 'Doğu Anadolu', 6),
    ('guneydogu-anadolu', 'Güneydoğu Anadolu', 7)
ON CONFLICT (code) DO NOTHING;
