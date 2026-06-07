# Disaster Recovery Runbook (Faz H5-5)

AdCast Pro üretim ortamı için felaket kurtarma prosedürü, hedefler ve test rejimi.

---

## SLO Hedefleri

| Metrik | Hedef | Mevcut Stratejimiz |
|---|---|---|
| **RPO** (max veri kaybı) | 24 saat | Günlük PG dump + MinIO mirror (Faz H1-4) |
| **RTO** (max kurtarma süresi) | 10 dakika | `pg_restore -Fc` tek transaction; dump ~10MB sıkıştırılmış |
| **Backup başarı oranı** | %99 / ay | `[backup] SUCCESS` logu Caddy access loga düşer; eksikliği uyarı |
| **Drill sıklığı** | Aylık | `bash bin/restore-drill.sh` cron veya manuel |

---

## Hangi Veri Korunuyor?

PostgreSQL `radio_saas` veritabanı tamamı — `pg_dump -Fc --no-owner --no-privileges` ile sıkıştırılmış custom format. İçinde:

- `users`, `admin_sessions`, `auth_refresh_tokens`
- `stations`, `station_stream_tokens`, `partner_api_keys`
- `content_plans`, `media_contents`, `media_jobs`
- `sponsors_ads`, `ad_campaigns`, `ad_airings`
- `support_tickets`, `support_ticket_messages`
- `audit_logs` (180 günle sınırlı — Faz H5-3)

**KAPSAM DIŞI:** MinIO `radio-media` bucket'taki ses dosyaları. Bunlar:
- Sponsor reklam asset'leri (admin tarafından upload edilen)
- Render edilmiş haber bültenleri (jobs queue tarafından üretilen)

Bunların ayrı bir backup'ı önemli ise `mc mirror local/radio-media s3://...` kuralı eklenmeli.

---

## Backup Lokasyonu

İki kopya:

1. `backup` container içinde: `/backups/db_<TIMESTAMP>.dump.gz` (volume `backup_data`, 30 günlük rolling delete)
2. MinIO `radio-backup` bucket'ı altında: `postgres/db_<TIMESTAMP>.dump.gz`

MinIO production'da ayrı disk veya off-site sync hedefi olarak ayarlanmalı.

---

## Restore Prosedürü (Gerçek Felaket)

### 1. Tetikleyici

PostgreSQL container `unhealthy` durumda kalıcı, veri dizini bozuk, ya da yanlışlıkla DROP TABLE çağrıldı.

### 2. Bağlamı dondur

```bash
docker compose -f docker-compose.prod.yml stop php-fpm worker nginx
```

Frontend yeni yazma denemesi yapmasın; restore sırasında bütünlük korunur.

### 3. Bozuk veri dizinini yedek koy

```bash
docker compose -f docker-compose.prod.yml stop postgres
docker volume create postgres_data_corrupt_$(date +%s)
# postgres_data → corrupt yedeği taşı (debug için)
```

### 4. En son dump'ı bul

```bash
docker exec radio-minio mc ls local/radio-backup/postgres/ | sort | tail -3
```

### 5. PG'yi temiz başlat + restore

```bash
docker compose -f docker-compose.prod.yml up -d postgres
sleep 5

# Dump'ı çek
docker exec radio-minio mc cp local/radio-backup/postgres/db_<TIMESTAMP>.dump.gz /tmp/r.dump.gz
docker cp radio-minio:/tmp/r.dump.gz ./restore.dump.gz

# Restore
gunzip -c ./restore.dump.gz | docker exec -i radio-postgres pg_restore \
    -U radio_saas -d radio_saas \
    --clean --if-exists --no-owner --no-privileges \
    --single-transaction
```

### 6. Sanity check

```bash
docker exec radio-postgres psql -U radio_saas -d radio_saas -c \
    "SELECT 'stations' as t, count(*) FROM stations
     UNION SELECT 'users', count(*) FROM users
     UNION SELECT 'audit_logs', count(*) FROM audit_logs;"
```

### 7. Servisleri geri aç

```bash
docker compose -f docker-compose.prod.yml up -d php-fpm worker nginx
curl https://radio.example.com/api/v1/healthz/deep | jq .
```

`status: "ok"` görene kadar trafiği Caddy/nginx'e aktarma.

---

## Aylık Drill

Manuel:
```bash
bash bin/restore-drill.sh
```

Cron (host crontab):
```cron
0 4 1 * *  /var/www/adcast/bin/restore-drill.sh >> /var/log/adcast-drill.log 2>&1
```

Drill raporu:
- En son dump dosyası adı
- pure `pg_restore` süresi
- Toplam download + restore + verify süresi
- Kritik tablo row count'ları (boş tablo varsa uyarır)
- RTO 10dk'yı aştıysa uyarır

---

## Backup Olmadığı Durum (RPO breach)

Backup eksikse veya 24 saatten eski ise:

1. `audit_logs` tablosundaki son `action='login'` ile son aktivite zamanını tespit edin (örnekleme).
2. Veri kaybını paydaşa raporlayın.
3. Son MinIO `radio-media` snapshot'ından partial recovery mümkün mü kontrol edin (render edilmiş feed'ler yine sunulabilir).
4. Manuel veri girişi protokolünü başlatın (radyo yöneticileri kendi planlarını yeniden oluşturur).

---

## Test Edilen Senaryolar

- [x] PG data dizini corruption (WSL2 hibernate kaynaklı; bu raporun yazılış sebebi)
- [x] Tek tablo DROP (audit_logs'a yanlışlıkla TRUNCATE)
- [ ] MinIO bucket silinmesi (manuel test bekliyor)
- [ ] Caddy ACME cert expiry (renewal failure)

Sıradaki drill öncesi eksik kategorileri tamamla.
