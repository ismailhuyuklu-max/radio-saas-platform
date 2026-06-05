# Production Checklist

## 1. Frontend Build

Build the Vben Admin frontend first so `frontend/dist/` exists:

```bash
cd frontend
npm ci
npm run build
```

If the workspace does not yet contain a real `package.json` and `tsconfig.json`, the build cannot run until those files are restored from the Vben root.

## 2. Start the Stack

Bring up the production stack:

```bash
docker compose -f docker-compose.prod.yml up --build -d
```

One-shot local bootstrap is also available:

```bash
./setup.sh
```

Local endpoints after bootstrap:

- Frontend and API gateway: `http://localhost:8080`
- API Gateway: `http://localhost:8080`
- MinIO Console: `http://localhost:9001`
- PostgreSQL: `localhost:5432`

Default admin login for the Vben panel:

- Username: `admin`
- Password: `123456`

Auth endpoints:

- `POST /api/v1/auth/login`
- `GET /api/v1/user/info`
- `GET /api/v1/auth/user`

## 3. Run Database Migrations

The local bootstrap script runs migrations after the database becomes healthy:

```bash
docker compose -f docker-compose.prod.yml run --rm migrate
```

## 4. Queue Worker

The production compose file runs `worker` as a dedicated long-lived service:

```bash
docker compose -f docker-compose.prod.yml logs -f worker
```

For a single-host VM deployment where PHP, the queue worker, and other background processes share one container, use Supervisor:

```bash
supervisord -c /etc/supervisor/conf.d/supervisord.conf
```

Supervisor program file:

- `docker/supervisor/supervisord.conf`
- `docker/supervisor/queue-worker.conf`

## 5. MinIO CORS Policy

Configure MinIO CORS through the server environment and make the local public bucket readable:

```bash
mc alias set local http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"
export MINIO_API_CORS_ALLOW_ORIGIN="https://app.example.com,https://admin.example.com"
```

The local Docker stack supplies localhost origins by default. Override `MINIO_API_CORS_ALLOW_ORIGIN` in production.

Quick reminder for first-time setup:

```bash
mc anonymous set download myminio/radio-media
```

## 6. Health Checks

Production sanity checks:

```bash
curl -f http://localhost/healthz
curl -f http://localhost/api/v1/feeds/sample-station/news.json
```

## 7. Operational Notes

- Keep `frontend/dist/` synchronized with the deployed static bundle.
- Keep PHP image builds immutable; do not bind-mount source code in production.
- Ensure `media_jobs` has a monitoring alert for failed or stuck jobs.
