# Aircast Pro Load Test Suite (Faz CTO-5)

## Senaryolar

| Dosya | Hedef | Süre |
|---|---|---|
| `smoke.js` | 100 VU sabit — günlük smoke | ~2 dk |
| `stress-1000.js` | 0 → 1000 VU ramp + sustain | ~9 dk |

## Çalıştırma

### k6 kurulu mu?
```bash
k6 version  # 0.50+
# Yoksa: https://k6.io/docs/get-started/installation/
```

### 1) Smoke (her PR sonrası — ~2 dk)

```bash
k6 run -e BASE=http://localhost:8080 -e ADMIN_PASS=123456 loadtest/smoke.js
```

**Hedef:** P95 < 500 ms, error rate < %1, login P95 < 1000 ms.

### 2) Stress 1000 VU (haftalık — ~9 dk)

```bash
# Önce: DB'yi 500 station + 50K plan ile doldur (varsa)
docker exec radio-php php /var/www/backend/bin/seed-load.php

# Test
k6 run -e BASE=http://localhost:8080 loadtest/stress-1000.js
```

**Hedef:** P95 < 1500 ms, gerçek 5xx error < %2 (429 rate-limit beklenir).

## Sonuçların değerlendirilmesi

| Metrik | Yeşil | Sarı | Kırmızı |
|---|---|---|---|
| P95 latency | < 500 ms | 500-1500 | > 1500 |
| Error rate (5xx) | < %0.5 | %0.5-2 | > %2 |
| Rate-limit hit (429) | < %5 | %5-15 | > %15 (kapasite az) |
| Login latency | < 1 sn | 1-2 sn | > 2 sn (bcrypt cost yüksek) |

## Yaygın bulgular ve aksiyonları

- **P95 > 1500 ms:**
  - `php artisan slow query log` → `/api/v1/metrics` `db_query_count`
  - Query plan: `EXPLAIN ANALYZE` ile missing index ara
  - `DB_SLOW_QUERY_MS=200` ile slow query log'u aç

- **5xx > %2:**
  - `docker logs radio-php --tail 100` → fatal/timeout
  - PG connection exhaustion: `max_connections=50` çok mu az?

- **Rate-limit > %15:**
  - nginx `limit_req zone=api rate=20r/s` artırılabilir
  - Veya istemci-side debounce/throttle

- **Login P95 > 2 sn:**
  - `BCRYPT_COST` çok yüksek (12 default; 11'e indir)

## CI entegrasyonu (opsiyonel)

`.github/workflows/load.yml` ile haftalık cron:

```yaml
on:
  schedule:
    - cron: '0 4 * * 1'  # Pazartesi 04:00 UTC
jobs:
  loadtest:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: grafana/k6-action@v0.3.1
        with:
          filename: loadtest/smoke.js
        env:
          BASE: ${{ secrets.STAGING_URL }}
          ADMIN_PASS: ${{ secrets.STAGING_PASS }}
```
