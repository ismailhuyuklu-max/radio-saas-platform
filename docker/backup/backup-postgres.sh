#!/bin/sh
# ============================================================================
# Faz H1-4: PostgreSQL günlük yedek + MinIO mirror.
# Çalıştırma: compose `backup` servisinde, cron'la her gece.
# 1) pg_dump -Fc | gzip → /backups/db_YYYY-MM-DD_HH-MM-SS.dump.gz
# 2) mc cp ile MinIO'nun radio-backup bucket'ına push
# 3) 30 günden eski yedekleri sil
# ============================================================================
set -eu

NOW=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_DIR="/backups"
DUMP_FILE="${BACKUP_DIR}/db_${NOW}.dump.gz"

PG_HOST="${POSTGRES_HOST:-postgres}"
PG_DB="${POSTGRES_DB:-radio_saas}"
PG_USER="${POSTGRES_USER:-radio_saas}"

mkdir -p "$BACKUP_DIR"

echo "[backup] $(date) - starting pg_dump for db=$PG_DB"

# pg_dump -Fc → custom format (compressed, restorable with pg_restore).
# PGPASSWORD env'i compose tarafından enjekte ediliyor.
PGPASSWORD="$POSTGRES_PASSWORD" pg_dump \
    -h "$PG_HOST" \
    -U "$PG_USER" \
    -d "$PG_DB" \
    -Fc \
    --no-owner --no-privileges \
    | gzip > "$DUMP_FILE"

SIZE=$(du -h "$DUMP_FILE" | cut -f1)
echo "[backup] $(date) - dump complete: $DUMP_FILE ($SIZE)"

# --- MinIO mirror ---
# minio-init bucket'ı zaten oluşturmuş olabilir; mb --ignore-existing ile
# güvenli (bu image kullanıcısı root tarafından kontrolde).
mc alias set local "${MINIO_ENDPOINT:-http://minio:9000}" \
    "${MINIO_ACCESS_KEY:-minioadmin}" "${MINIO_SECRET_KEY:-minioadmin123}" \
    >/dev/null 2>&1

mc mb --ignore-existing local/radio-backup >/dev/null 2>&1
mc cp "$DUMP_FILE" "local/radio-backup/postgres/db_${NOW}.dump.gz"
echo "[backup] $(date) - mirrored to MinIO radio-backup/postgres/"

# --- Retention: 30 günden eski yerel yedekleri sil ---
find "$BACKUP_DIR" -name 'db_*.dump.gz' -type f -mtime +30 -print -delete
echo "[backup] $(date) - retention sweep complete"

echo "[backup] $(date) - SUCCESS"
