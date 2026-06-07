#!/usr/bin/env bash
# =============================================================================
# AdCast Pro — Production Smoke Test (Faz REBRAND-DEPLOY)
# =============================================================================
# Deploy sonrası kritik endpoint'leri ve davranışları doğrular.
# Çalıştır: bash bin/smoke-test.sh [URL]
#   URL: http://localhost:8080 (default) veya https://adcastpro.com
# =============================================================================

set -uo pipefail

URL="${1:-${SMOKE_URL:-http://localhost:8080}}"
PASS=0
FAIL=0
WARN=0

ok() { echo "  ✓ $*"; PASS=$((PASS+1)); }
ko() { echo "  ✗ $*"; FAIL=$((FAIL+1)); }
wn() { echo "  ⚠ $*"; WARN=$((WARN+1)); }

echo "=== AdCast Pro Smoke Test — $URL ==="
echo

# ---------- 1. Erişilebilirlik ----------
echo "[1] Erişilebilirlik"
CODE=$(curl -sS -o /dev/null -w "%{http_code}" -m 10 "$URL/" || echo "000")
[[ "$CODE" == "200" ]] && ok "GET / → 200" || ko "GET / → $CODE"

# ---------- 2. Login sayfası ----------
echo "[2] Login sayfası"
BODY=$(curl -sS -m 10 "$URL/login" || true)
echo "$BODY" | grep -q 'AdCast Pro' && ok "Login HTML 'AdCast Pro' içeriyor" || ko "Login HTML 'AdCast Pro' içermiyor"
echo "$BODY" | grep -q '<div id="app">' && ok "SPA mount point #app var" || ko "#app yok"

# ---------- 3. Healthz (deep) ----------
echo "[3] Healthz endpoint"
H=$(curl -sS -m 10 "$URL/api/v1/healthz/deep" 2>&1 || true)
echo "$H" | grep -q '"status"' && ok "Healthz cevap veriyor" || ko "Healthz cevap vermiyor"
echo "$H" | grep -q '"ok"' && ok "Healthz status=ok" || wn "Healthz status değil 'ok'"

# ---------- 4. API auth required ----------
echo "[4] API auth gating"
CODE=$(curl -sS -o /dev/null -w "%{http_code}" -m 10 "$URL/api/v1/stations")
[[ "$CODE" == "401" || "$CODE" == "403" ]] && ok "Korumalı endpoint 401/403 (giriş gerekli)" || ko "Korumalı endpoint $CODE (401/403 bekleniyordu)"

# ---------- 5. Security headers ----------
echo "[5] Security headers"
HDR=$(curl -sSI -m 10 "$URL/" 2>&1)
for h in "X-Content-Type-Options" "X-Frame-Options" "Referrer-Policy" "Content-Security-Policy" "Permissions-Policy"; do
  echo "$HDR" | grep -qi "^$h:" && ok "Header $h" || ko "Header $h eksik"
done
echo "$HDR" | grep -qi "^Strict-Transport-Security:" && ok "HSTS" || wn "HSTS eksik (HTTP'de normal, HTTPS'de gerekli)"

# ---------- 6. Logo + static asset ----------
echo "[6] Static asset"
CODE=$(curl -sS -o /dev/null -w "%{http_code}" -m 10 "$URL/adcastpro-logo.png")
[[ "$CODE" == "200" ]] && ok "Logo PNG erişilebilir" || ko "Logo PNG → $CODE"

# ---------- 7. Login flow ----------
echo "[7] Login flow"
COOKIE_JAR=$(mktemp)
trap "rm -f $COOKIE_JAR" EXIT
# CSRF token al
curl -sS -c "$COOKIE_JAR" -m 10 "$URL/api/v1/auth/csrf" -o /dev/null
CSRF=$(grep -o 'csrf_token[[:space:]]*[^[:space:]]*' "$COOKIE_JAR" | awk '{print $NF}' | tail -1)
if [[ -n "$CSRF" ]]; then
  ok "CSRF token alındı (${CSRF:0:8}...)"
  # Login dene (admin default credential — production'da değişmiş olmalı!)
  RESP=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -m 10 \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
    -X POST "$URL/api/v1/auth/login" \
    -d '{"username":"admin","password":"admin_test_wrong"}' 2>&1)
  echo "$RESP" | grep -q '"code"' && ok "Login endpoint cevap zarfı var" || ko "Login zarfı yok"
  echo "$RESP" | grep -qE '(401|403|invalid|hatal)' && ok "Hatalı şifre reddedildi" || wn "Hatalı şifre cevabı belirsiz"
else
  wn "CSRF token alınamadı"
fi

# ---------- 8. Rate limit (login brute force koruması) ----------
echo "[8] Rate limit (login burst)"
HIT_429=0
for i in {1..10}; do
  C=$(curl -sS -o /dev/null -w "%{http_code}" -m 5 -X POST \
    -H "Content-Type: application/json" \
    "$URL/api/v1/auth/login" -d '{"username":"x","password":"x"}')
  [[ "$C" == "429" ]] && HIT_429=$((HIT_429+1))
done
[[ "$HIT_429" -gt 0 ]] && ok "Rate limit aktif ($HIT_429/10 burst 429 aldı)" || wn "Rate limit henüz tetiklenmedi (10 istek yetersiz olabilir)"

# ---------- Özet ----------
echo
echo "=========================================="
echo "  Smoke Test Sonucu"
echo "=========================================="
echo "  Geçen: $PASS"
echo "  Başarısız: $FAIL"
echo "  Uyarı: $WARN"
[[ "$FAIL" -eq 0 ]] && { echo "  SONUÇ: ✓ HAZIR"; exit 0; }
echo "  SONUÇ: ✗ $FAIL kritik hata"
exit 1
