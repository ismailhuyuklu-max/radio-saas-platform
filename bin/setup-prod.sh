#!/usr/bin/env bash
# =============================================================================
# Aircast Pro — Üretim Ortamı Hızlı Kurulum (Faz H3-1)
# =============================================================================
# Bu script:
#   1. .env.production yoksa, .env.production.example'ı kopyalar.
#   2. REPLACE_WITH_... placeholder'larını openssl ile güvenli secret'larla
#      doldurur (mevcut değerleri override ETMEZ — idempotent).
#   3. docker compose -f docker-compose.prod.yml config ile doğrular.
#
# Çalıştırma (proje kökünden):
#   bash bin/setup-prod.sh
#
# WSL2 / Windows uyarısı: PostgreSQL data dizini WSL2 hibernate sırasında
# corrupt olabilir (pg_filenode). Üretim için gerçek Linux host şart.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE=".env.production"
EXAMPLE_FILE=".env.production.example"

if [ ! -f "$EXAMPLE_FILE" ]; then
    echo "FATAL: $EXAMPLE_FILE bulunamadı." >&2
    exit 1
fi

# Idempotent: var olan dosyayı override etme.
if [ ! -f "$ENV_FILE" ]; then
    echo "[setup-prod] .env.production yok → .env.production.example üzerinden oluşturuluyor"
    cp "$EXAMPLE_FILE" "$ENV_FILE"
fi

# openssl mevcut mu?
if ! command -v openssl >/dev/null 2>&1; then
    echo "FATAL: openssl yüklü değil. Bir base64/hex generator gerekli." >&2
    exit 1
fi

# Tek bir REPLACE_WITH... satırını üretilen değerle değiştir.
# $1 = key adı (örn. APP_KEY); $2 = generator komutu (`base64 32` / `hex 24`).
replace_if_placeholder() {
    local key="$1"
    local generator="$2"
    local current_line
    current_line=$(grep "^${key}=" "$ENV_FILE" || true)

    if [ -z "$current_line" ]; then
        echo "[setup-prod] uyarı: $key dosyada yok, atlanıyor"
        return
    fi
    # Yalnız placeholder ise değiştir.
    if echo "$current_line" | grep -q 'REPLACE_WITH_'; then
        local value
        # openssl rand -base64 32 | openssl rand -hex 24 gibi
        # shellcheck disable=SC2086
        value=$(openssl rand $generator)
        # Eşittenin sağındaki tüm karakterleri yeni değerle değiştir.
        # `|` ayırıcı çünkü base64 çıktısı / + içerebilir.
        sed -i.bak "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
        echo "[setup-prod] $key → güvenli random ile dolduruldu"
    else
        echo "[setup-prod] $key zaten ayarlı → korundu"
    fi
}

# 32-byte base64 ≈ 44 char (APP_KEY için JWT HS256 ideal)
replace_if_placeholder APP_KEY "-base64 32"
# 24-byte hex ≈ 48 char (DB password için — özel karakter yok, escape kolay)
replace_if_placeholder POSTGRES_PASSWORD "-hex 24"
# MinIO root: 16-byte hex (32 char) — user, 24-byte hex (48 char) — pass
replace_if_placeholder MINIO_ROOT_USER "-hex 16"
replace_if_placeholder MINIO_ROOT_PASSWORD "-hex 24"

# Yedek .bak dosyasını sil
rm -f "${ENV_FILE}.bak"

echo
echo "[setup-prod] .env.production hazır. Compose doğrulaması:"
if docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE" config --quiet; then
    echo "[setup-prod] ✓ docker compose config geçerli"
else
    echo "[setup-prod] ✗ compose config doğrulanamadı; .env.production'ı kontrol edin" >&2
    exit 1
fi

# Sızdırma riski: secret'ları stdout'a basma. Sadece dosya yolunu hatırlat.
cat <<EOF

═══════════════════════════════════════════════════════════════════════
  KURULUM TAMAMLANDI
═══════════════════════════════════════════════════════════════════════

  Sonraki adımlar:

  1) .env.production'ı gözden geçirin:
       - APP_URL (gerçek prod domain)
       - MINIO_API_CORS_ALLOW_ORIGIN
       - TRUSTED_PROXY_IPS (Caddy/nginx/Cloudflare IP'leri)

  2) Cluster'ı başlatın:
       docker compose --env-file .env.production -f docker-compose.prod.yml up -d

  3) Admin kullanıcısının şifresini değiştirin:
       (varsayılan: admin / admin → ilk girişte mutlaka değiştir)

  4) Backup'ı test edin:
       docker compose exec backup /usr/local/bin/backup-postgres.sh

  5) NOC sayfasından sistem sağlığını izleyin:
       https://radio.example.com/radio-platform/noc

  .env.production REPO'ya KOMMİT EDİLMEMELİDİR (.gitignore zaten dışlar).

═══════════════════════════════════════════════════════════════════════
EOF
