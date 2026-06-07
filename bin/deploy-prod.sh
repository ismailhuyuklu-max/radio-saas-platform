#!/usr/bin/env bash
# =============================================================================
# AdCast Pro — Production Deploy (Faz REBRAND-DEPLOY)
# =============================================================================
# Sunucuya bootstrap çalıştıktan SONRA çalıştırılır.
# Repo'yu pull eder, .env.production'ı (yoksa) üretir, prod stack'i build edip
# çalıştırır, migration koşar, healthcheck doğrular.
#
# Çalıştır: bash bin/deploy-prod.sh
#   FIRST_RUN=1 bash bin/deploy-prod.sh  → ilk kurulum (repo clone + setup-prod)
#
# Domain DNS'i hazırsa: USE_TLS=1 bash bin/deploy-prod.sh
# (Caddy sidecar Let's Encrypt çeker — adcastpro.com için A record şart)
# =============================================================================

set -euo pipefail
exec > >(tee -a /var/log/adcast-deploy.log) 2>&1
echo "=== AdCast Pro Deploy — $(date -Iseconds) ==="

REPO_URL="${REPO_URL:-https://github.com/ismailhuyuklu-max/radio-saas-platform.git}"
APP_DIR="${APP_DIR:-/var/www/adcastpro}"
FIRST_RUN="${FIRST_RUN:-0}"
USE_TLS="${USE_TLS:-0}"
BRANCH="${BRANCH:-main}"

# ---------- 1. Repo clone / pull ----------
if [[ "$FIRST_RUN" == "1" || ! -d "$APP_DIR/.git" ]]; then
  echo "[1/8] İlk kurulum — repo clone..."
  mkdir -p "$(dirname "$APP_DIR")"
  if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    echo "  ⚠ PRIVATE REPO için GITHUB_TOKEN env var gerekli."
    echo "     GitHub → Settings → Developer settings → Personal access tokens"
    echo "     (fine-grained, sadece bu repo'ya read access) üret, sonra:"
    echo "     GITHUB_TOKEN=ghp_xxxxx FIRST_RUN=1 bash $0"
    exit 1
  fi
  AUTH_URL="${REPO_URL/https:\/\//https:\/\/$GITHUB_TOKEN@}"
  git clone --branch "$BRANCH" --depth 50 "$AUTH_URL" "$APP_DIR"
  cd "$APP_DIR"
else
  echo "[1/8] Repo pull..."
  cd "$APP_DIR"
  git fetch --depth 50 origin "$BRANCH"
  CURRENT=$(git rev-parse HEAD)
  git reset --hard "origin/$BRANCH"
  NEW=$(git rev-parse HEAD)
  echo "  ✓ $CURRENT → $NEW"
fi

# ---------- 2. .env.production ----------
echo "[2/8] .env.production hazırlığı..."
if [[ ! -f .env.production ]]; then
  echo "  → setup-prod.sh çağırılıyor (güçlü secret üretimi)..."
  bash bin/setup-prod.sh
  echo "  ✓ .env.production üretildi"
else
  echo "  ✓ .env.production zaten var (korundu)"
fi

# Production URL'i parametre olarak override
if [[ -n "${PROD_DOMAIN:-}" ]]; then
  sed -i "s|^APP_URL=.*|APP_URL=https://${PROD_DOMAIN}|" .env.production
  sed -i "s|^MINIO_API_CORS_ALLOW_ORIGIN=.*|MINIO_API_CORS_ALLOW_ORIGIN=https://${PROD_DOMAIN}|" .env.production
  echo "  ✓ APP_URL = https://${PROD_DOMAIN}"
fi

# ---------- 3. Frontend build ----------
echo "[3/8] Frontend build (host npm gerekirse)..."
if command -v node &>/dev/null; then
  cd frontend
  npm ci --prefer-offline --no-audit --no-fund
  npm run build
  cd ..
  echo "  ✓ Frontend dist/ üretildi ($(du -sh frontend/dist | cut -f1))"
else
  echo "  ⚠ Node yok host'ta; Docker build içinde yapılacak (yavaş)"
fi

# ---------- 4. Docker stack build ----------
echo "[4/8] Docker images build..."
PROFILE_ARG=""
[[ "$USE_TLS" == "1" ]] && PROFILE_ARG="--profile tls"
docker compose --env-file .env.production -f docker-compose.prod.yml $PROFILE_ARG build --pull

# ---------- 5. Stack başlat ----------
echo "[5/8] Stack başlatılıyor..."
docker compose --env-file .env.production -f docker-compose.prod.yml $PROFILE_ARG up -d

# ---------- 6. Healthy bekle ----------
echo "[6/8] Servislerin healthy olmasını bekliyorum (max 120s)..."
for i in {1..40}; do
  UNHEALTHY=$(docker compose --env-file .env.production -f docker-compose.prod.yml ps --format json 2>/dev/null | \
    jq -r 'select(.Health != null and .Health != "healthy" and .Health != "") | .Name' 2>/dev/null || true)
  if [[ -z "$UNHEALTHY" ]]; then
    echo "  ✓ Tüm healthcheck'li servisler healthy"
    break
  fi
  echo "  ... bekliyorum: $UNHEALTHY"
  sleep 3
done

# ---------- 7. Migration ----------
echo "[7/8] DB migration..."
docker compose --env-file .env.production -f docker-compose.prod.yml exec -T php \
  php /var/www/backend/bin/migrate.php || {
    echo "  ⚠ Migration hata verdi; manuel kontrol gerekli."
  }

# ---------- 8. Smoke test ----------
echo "[8/8] Smoke test..."
bash bin/smoke-test.sh

echo
echo "=========================================="
echo "  AdCast Pro Deploy TAMAMLANDI"
echo "=========================================="
echo "  Docker stack:"
docker compose --env-file .env.production -f docker-compose.prod.yml ps --format "table {{.Service}}\t{{.Status}}\t{{.Ports}}"
echo
[[ "$USE_TLS" == "1" ]] && echo "  URL: https://${PROD_DOMAIN:-adcastpro.com}"
[[ "$USE_TLS" != "1" ]] && echo "  URL: http://$(hostname -I | awk '{print $1}'):8080 (IP üzerinden HTTP-only)"
echo "=========================================="
