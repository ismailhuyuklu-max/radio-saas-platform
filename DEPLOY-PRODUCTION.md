# AdCast Pro — Production Deployment Runbook

**Hedef ortam:** Ubuntu 24.04 VDS · 8 vCPU · 8GB RAM · 120GB SSD
**Domain:** adcastpro.com (DNS A record VDS IP'sine yönlendirilmiş olmalı)
**Bu doküman:** Sıfırdan production ortamı kurma, deploy etme, smoke test çalıştırma.

---

## ÖZET — 4 Komutla Deploy

```bash
# Sunucuda root olarak:
cd /tmp && curl -fsSL https://raw.githubusercontent.com/ismailhuyuklu-max/radio-saas-platform/main/bin/server-bootstrap.sh -o bootstrap.sh
# (PRIVATE repo için curl yerine: git clone önce; aşağı bak)

# 1. Server bootstrap (Docker + firewall + fail2ban + swap)
bash bootstrap.sh

# 2. Repo clone + .env üret + stack build + healthy bekle + migration + smoke test
GITHUB_TOKEN=ghp_xxx PROD_DOMAIN=adcastpro.com FIRST_RUN=1 USE_TLS=1 \
  bash /var/www/adcastpro/bin/deploy-prod.sh
```

Sonra `https://adcastpro.com/login` açılır.

---

## ÖN HAZIRLIK (yerel makinende, sunucuya bağlanmadan önce)

### 1. SSH key oluştur

```powershell
# PowerShell — Windows
ssh-keygen -t ed25519 -f $env:USERPROFILE\.ssh\id_ed25519 -N '""' -C "adcastpro-deploy"
```

```bash
# Bash / Linux / macOS
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519 -N "" -C "adcastpro-deploy"
```

### 2. Public key'i sunucuya yükle

```powershell
# Windows PowerShell — şifre sadece bu komutta 1 kez sorulur
Get-Content "$env:USERPROFILE\.ssh\id_ed25519.pub" | ssh root@178.210.168.74 "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

```bash
# Linux/macOS
ssh-copy-id -i ~/.ssh/id_ed25519.pub root@178.210.168.74
```

### 3. Bağlantıyı doğrula

```bash
ssh -i ~/.ssh/id_ed25519 root@178.210.168.74 "hostname && uptime"
```

Şifre sormadan cevap dönerse → key auth çalışıyor.

### 4. GitHub PRIVATE repo için Personal Access Token üret

1. https://github.com/settings/personal-access-tokens/new
2. **Fine-grained**, sadece `radio-saas-platform` repo'suna **Contents: Read** yetkisi
3. Expiration: 90 gün
4. Üret, token'ı sakla (örn. `ghp_xxxxxxxxxxxx`)

### 5. DNS Kontrol (TLS isteniyorsa)

```bash
dig +short adcastpro.com
# Beklenen: 178.210.168.74
# Yoksa: DNS sağlayıcında A record ekle, propagation için 5-10 dakika bekle
```

---

## SUNUCU KURULUMU (sunucuda root olarak)

### A) Server Bootstrap — `bin/server-bootstrap.sh`

İlk komutu sunucuda çalıştır:

```bash
# Repo henüz yok — bootstrap script'i tek dosya indir
mkdir -p /var/www && cd /var/www
# PRIVATE repo: clone öncesi token ile
git clone -b main https://ghp_YOUR_TOKEN_HERE@github.com/ismailhuyuklu-max/radio-saas-platform.git adcastpro
cd adcastpro

# Bootstrap
bash bin/server-bootstrap.sh
```

**Bootstrap yapacakları:**
1. APT update + upgrade (10 dk)
2. Temel paketler (curl, git, jq, ufw, fail2ban, ...)
3. **4GB swap** (8GB RAM için OOM koruması)
4. **Docker Engine + Compose plugin**
5. **UFW firewall** — sadece 22, 80, 443 açık
6. **fail2ban** — SSH brute force koruması (3 hatalı → 2h ban)
7. **unattended-upgrades** — otomatik güvenlik yaması
8. **SSH hardening** — password auth OFF, root login key-only, MaxAuthTries=3
   *(SSH key yüklü olduğu için güvenli kilitleme yapar)*
9. Logrotate (`/var/log/adcast-*.log` 12 hafta)
10. Timezone Europe/Istanbul, locale tr_TR.UTF-8

**Süre:** ~5-8 dakika
**Çıktı:** `/var/log/adcast-bootstrap.log`

### B) Production Deploy — `bin/deploy-prod.sh`

```bash
# İlk deploy — .env üret + Docker stack build + migration + smoke test
PROD_DOMAIN=adcastpro.com USE_TLS=1 FIRST_RUN=1 bash bin/deploy-prod.sh
```

**Deploy yapacakları:**
1. Repo clone/pull (ana branch)
2. `.env.production` üret (yoksa) — `bin/setup-prod.sh` çağırır
   - `APP_KEY`: `openssl rand -base64 32`
   - `POSTGRES_PASSWORD`, `MINIO_ROOT_PASSWORD`: `openssl rand -hex 24`
3. Frontend `npm ci && npm run build` (varsa Node host'ta)
4. Docker images build (`--pull`)
5. Stack başlat (Caddy TLS sidecar dahil)
6. Tüm healthcheck'ler healthy olana kadar bekle (max 120s)
7. DB migration
8. Smoke test

**Süre:** ilk run ~10-15 dakika (image build + npm + Let's Encrypt cert)

### C) Smoke Test — `bin/smoke-test.sh`

```bash
bash bin/smoke-test.sh https://adcastpro.com
```

**Kontrol ettikleri:**
1. `GET /` → 200
2. Login HTML'i 'AdCast Pro' içeriyor mu
3. `/api/v1/healthz/deep` cevap veriyor mu
4. Korumalı API endpoint 401 dönüyor mu (auth gating)
5. Security headers (CSP, X-Frame-Options, Referrer-Policy, ...)
6. Logo PNG erişilebilir mi
7. CSRF token akışı
8. Login rate-limit (brute force koruması)

---

## DEPLOY SONRASI — İLK KULLANICI

```bash
# Default admin user mevcut (parola .env'de değil — DB seed'inde belirleniyor)
# İlk login: admin / admin (DEĞİŞTİR!)
# Sonra Operations Center → Kullanıcılar → admin → şifre değiştir
```

**MUTLAKA:**
1. İlk login sonrası admin şifresini değiştir
2. MFA (TOTP) aktive et (Settings → Security → Enable 2FA)
3. Bir kurtarma kodu yazdır/sakla

---

## RUTIN BAKIM

### Yeni release deploy
```bash
# Sunucuda
cd /var/www/adcastpro
PROD_DOMAIN=adcastpro.com USE_TLS=1 bash bin/deploy-prod.sh
```

### Log kontrol
```bash
docker compose -f docker-compose.prod.yml logs --tail=100 -f php
docker compose -f docker-compose.prod.yml logs --tail=100 -f nginx
tail -f /var/log/adcast-deploy.log
```

### Backup restore drill (CTO-8 — her ay)
```bash
bash bin/restore-drill.sh
```

### k6 load test
```bash
docker run --network host -v $(pwd)/loadtest:/scripts grafana/k6 run /scripts/smoke.js
```

### Disk kullanımı izle
```bash
df -h
docker system df
# Temizlik
docker system prune -af --volumes  # DİKKAT: kullanılmayan volume'leri SİLER
```

---

## SORUN GİDERME

### Container unhealthy
```bash
docker compose -f docker-compose.prod.yml ps
docker inspect <container> --format '{{json .State.Health}}' | jq
```

### Migration başarısız
```bash
docker compose -f docker-compose.prod.yml exec php php /var/www/backend/bin/migrate.php --rollback
# DB'den manuel kontrol:
docker compose -f docker-compose.prod.yml exec postgres psql -U radio_saas -d radio_saas -c "\dt"
```

### TLS sertifika almıyor
```bash
docker compose -f docker-compose.prod.yml logs caddy | grep -iE "(error|acme)"
# Çözüm: DNS A record kontrol et, port 80 açık mı (UFW)
dig +short adcastpro.com
ufw status | grep 80
```

### Şifre değiştir — root user
```bash
# Bootstrap sonrası SSH key auth zorunlu; password auth kapalı.
# Şifreyi sıfırlamak için VDS sağlayıcı web konsolundan giriş gerekli.
```

---

## GÜVENLİK NOTLARI

- **`.env.production` ASLA repo'ya commit edilmez** (`.gitignore`'da)
- **SSH password auth KAPALI** (bootstrap sonrası); kaybetmemek için key'i yedekle (örn. KeePass)
- **GitHub PAT** kullandıktan sonra sunucudan sil:
  ```bash
  cd /var/www/adcastpro && git remote set-url origin https://github.com/ismailhuyuklu-max/radio-saas-platform.git
  ```
  (token URL'den kalkar; sonraki pull'larda SSH deploy key veya yeni token gerekir)
- **MFA (TOTP)** tüm admin user'larda zorunlu olmalı
- **`docker system prune`** sadece kullanılmayan asset'leri siler; aktif volume'lere dokunmaz

---

## PRODUCTION URL

- Web Panel: **https://adcastpro.com**
- Health: https://adcastpro.com/api/v1/healthz/deep
- MinIO Console: **kapalı** (UFW 9001 portu açık değil); ihtiyaç olursa SSH tunnel:
  ```bash
  ssh -L 9001:localhost:9001 root@178.210.168.74
  # Sonra browser'da: http://localhost:9001
  ```

---

## DEPLOYMENT VERSION HISTORY

| Sürüm | Tag | Tarih | Açıklama |
|-------|-----|-------|----------|
| v2.0.0 | v2.0.0-rebrand | 2026-06-07 | İlk AdCast Pro markalı release |
| v2.0.1 | — | 2026-06-07 | Logo wordmark fix |
| v2.0.2 | — | 2026-06-07 | Yeni transparent logo |
| v2.0.3 | — | 2026-06-07 | Logo boyutu büyütüldü |
| v2.0.4 | — | 2026-06-07 | Tagline güncellendi |
| v2.1.0 | (planlanan) | — | Production deploy artifact (bu doküman + script paketi) |
