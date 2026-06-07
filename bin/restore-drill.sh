#!/usr/bin/env bash
# =============================================================================
# Aircast Pro — Backup Restore Drill (Faz H5-5)
# =============================================================================
# Disaster Recovery sigortası: Backup'ları RESTORE EDEBİLİYORSAN backup'ın
# vardır; teyit etmeden backup "Schrödinger'in backup'ıdır".
#
# Bu script:
#   1) MinIO'dan en yeni postgres dump'ı çeker.
#   2) Geçici tek-seferlik bir PG container'a restore eder.
#   3) Tablo başına row count'ları yazdırır → veri tutarlılığı sanity check'i.
#   4) Restore süresini ölçer → RTO (Recovery Time Objective) raporu.
#   5) Drill PG container'ını siler.
#
# Çalıştırma (proje kökünden):
#   bash bin/restore-drill.sh
#
# Beklenen RPO: 24h (backup günde 1 → max 24h veri kaybı).
# Beklenen RTO: <10 dk (drill ile ölç; aşarsa backup sıkıştırma + paralel
#                       restore parametrelerini gözden geçir).
# =============================================================================
set -euo pipefail

# --- Konfig ---
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-.env.production}"
DRILL_CONTAINER="aircast-restore-drill"
DRILL_DB="restore_drill"
DRILL_PASSWORD=$(openssl rand -hex 16)
DRILL_PORT="${DRILL_PORT:-54329}"
WORKDIR=$(mktemp -d -t aircast-drill-XXXXXX)
trap 'rm -rf "$WORKDIR"; docker rm -f "$DRILL_CONTAINER" >/dev/null 2>&1 || true' EXIT

cd "$(dirname "$0")/.."

# Renkli + zaman damgalı log
log() { printf '\033[36m[restore-drill]\033[0m %s — %s\n' "$(date +%H:%M:%S)" "$1"; }
fail() { printf '\033[31m[FAIL]\033[0m %s\n' "$1" >&2; exit 1; }

START=$(date +%s)

# --- 1. En son backup'ı bul ---
log "MinIO'dan en yeni dump aranıyor"
if ! docker ps --format '{{.Names}}' | grep -q '^radio-minio$'; then
    fail "radio-minio container çalışmıyor. Önce: docker compose -f $COMPOSE_FILE up -d minio"
fi

LATEST=$(docker exec radio-minio mc ls --json local/radio-backup/postgres/ 2>/dev/null \
    | awk -F'"' '/"key":/ {print $4}' | sort | tail -1 || true)

if [ -z "$LATEST" ]; then
    fail "radio-backup bucket'ında dump bulunamadı. Önce backup container'ı çalıştırın."
fi

log "Bulundu: $LATEST"
DUMP_LOCAL="$WORKDIR/$(basename "$LATEST")"
docker exec radio-minio mc cp "local/radio-backup/postgres/$LATEST" "/tmp/restore.dump.gz" >/dev/null
docker cp "radio-minio:/tmp/restore.dump.gz" "$DUMP_LOCAL"
log "İndirildi: $(du -h "$DUMP_LOCAL" | cut -f1) → $DUMP_LOCAL"

# --- 2. Drill PG container'ı başlat ---
log "Geçici PG container başlatılıyor (port $DRILL_PORT)"
docker run -d --name "$DRILL_CONTAINER" --rm \
    -e "POSTGRES_DB=$DRILL_DB" \
    -e "POSTGRES_USER=radio_saas" \
    -e "POSTGRES_PASSWORD=$DRILL_PASSWORD" \
    -p "$DRILL_PORT:5432" \
    postgres:16-alpine >/dev/null

log "PG'in hazır olması bekleniyor"
until docker exec "$DRILL_CONTAINER" pg_isready -U radio_saas -d "$DRILL_DB" >/dev/null 2>&1; do
    sleep 1
done

# --- 3. Restore ---
log "pg_restore başladı (en yorucu adım)"
RESTORE_START=$(date +%s)
gunzip -c "$DUMP_LOCAL" \
    | docker exec -i "$DRILL_CONTAINER" pg_restore \
        -U radio_saas -d "$DRILL_DB" \
        --no-owner --no-privileges --single-transaction \
        2>&1 | tail -20 || fail "pg_restore başarısız"

RESTORE_END=$(date +%s)
RESTORE_SEC=$((RESTORE_END - RESTORE_START))
log "Restore tamamlandı: ${RESTORE_SEC}s"

# --- 4. Sanity check: kritik tablolarda satır say ---
log "Row count sanity check"
PSQL_EXEC() {
    docker exec "$DRILL_CONTAINER" psql -U radio_saas -d "$DRILL_DB" -tAc "$1" 2>/dev/null
}

declare -A CRITICAL=(
    [stations]="aktif radyo sayısı"
    [users]="kullanıcı sayısı"
    [audit_logs]="audit log sayısı"
    [content_plans]="içerik planı sayısı"
    [ad_campaigns]="kampanya sayısı"
    [partner_api_keys]="partner API key sayısı"
)

ZERO_TABLES=0
for table in "${!CRITICAL[@]}"; do
    count=$(PSQL_EXEC "SELECT count(*) FROM ${table};" || echo "?")
    label="${CRITICAL[$table]}"
    printf '  %-22s %10s  (%s)\n' "$table" "$count" "$label"
    if [ "$count" = "0" ]; then
        ZERO_TABLES=$((ZERO_TABLES + 1))
    fi
done

# --- 5. RTO raporu ---
END=$(date +%s)
TOTAL=$((END - START))

cat <<EOF

═══════════════════════════════════════════════════════════════════════
  DRILL RAPORU
═══════════════════════════════════════════════════════════════════════
  Backup adı       : $LATEST
  Restore süresi   : ${RESTORE_SEC}s (pure pg_restore)
  Toplam süre      : ${TOTAL}s (download + restore + verify)

  RPO  (max veri kaybı)  : 24h (backup günlük)
  RTO  (recovery hedefi) : 10dk
  RTO  (gerçek ölçüm)    : $((TOTAL / 60))dk $((TOTAL % 60))s

EOF

if [ "$TOTAL" -gt 600 ]; then
    printf '  \033[33m%s\033[0m\n' "WARN: RTO 10dk asildi — pg_dump -j parallel + sikistirma seviyesi ayarla."
fi
if [ "$ZERO_TABLES" -gt 0 ]; then
    printf '  \033[33m%s\033[0m\n' "WARN: $ZERO_TABLES kritik tablo BOS dondu — backup eksik veya yanlis DB."
fi
if [ "$TOTAL" -le 600 ] && [ "$ZERO_TABLES" -eq 0 ]; then
    printf '  \033[32m%s\033[0m\n' "OK: Drill basarili — backup restore edilebilir, RTO hedefinde."
fi

echo "═══════════════════════════════════════════════════════════════════════"
