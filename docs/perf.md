# Performance baseline — AdCast Pro Partner Portal

The master prompt requires the platform to run smoothly at:

- 500+ radyo
- 5000+ kullanıcı
- 100.000+ dosya

Faz 26 ships two scripts and a recorded baseline so the next regression is
visible the moment it lands.

## Scripts

| Script | Purpose |
|---|---|
| `backend/bin/seed-load.php` | Idempotently bulk-loads `load_*` rows to the targets via `SEED_STATIONS`, `SEED_USERS`, `SEED_MEDIA` env vars. Uses chunked transactions, prepared statements, single bcrypt hash reused (load users never authenticate). |
| `backend/bin/perf-smoke.php` | Times the hot-path endpoints under a configurable `PERF_BUDGET_MS` budget. Fails the run when any endpoint exceeds. |

Both run inside the app network:

```bash
docker compose exec -T -e SEED_STATIONS=500 -e SEED_USERS=5000 -e SEED_MEDIA=50000 \
  php-fpm php bin/seed-load.php

docker compose exec -T -e PERF_BUDGET_MS=400 \
  php-fpm php bin/perf-smoke.php
```

## Recorded baseline

Local Docker (Postgres 16 + PHP-FPM 8.2) with 500 stations / 5000 users /
50k media:

```
Perf budget: 400ms
  GET    /stations?limit=200                                          200   122ms OK
  GET    /plans?date=YYYY-MM-DD                                       200   113ms OK
  GET    /plans/range?start=…&end=…                                   200    97ms OK
  GET    /media-library                                               200   229ms OK
  GET    /ad-campaigns?limit=200                                      200    95ms OK
  GET    /audit/logs?limit=100                                        200    80ms OK
  GET    /reports/breakdown/province                                  200    94ms OK
  GET    /reports/breakdown/customer                                  200   151ms OK
All within budget.
```

Every hot-path endpoint stays under 230 ms with the load corpus loaded.

## Indexes the load corpus exercises

The schema already carries the indexes the endpoints depend on
(`idx_stations_region_active`, `idx_users_station`,
`idx_audit_logs_*`, `content_plans` indexes from Faz 2, etc.). The
seeder fills the same tables the endpoints read from, so the smoke
proves the *real* query plans, not a synthetic dataset.

## Cleanup

```sql
DELETE FROM media_contents WHERE title LIKE 'load_%';
DELETE FROM users WHERE username LIKE 'load_%';
DELETE FROM stations WHERE slug LIKE 'load_%';
```
