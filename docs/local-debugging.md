# Localhost Debug Guide

## Frontend Won't Open On `http://localhost:3000`

The optional Vite `frontend` service runs under the `dev` profile and exposes port 3000 on the host. Start it and check the service state:

```bash
docker compose -f docker-compose.prod.yml --profile dev up -d frontend
docker compose -f docker-compose.prod.yml --profile dev ps
docker compose -f docker-compose.prod.yml --profile dev logs -f frontend
```

If `ERR_CONNECTION_REFUSED` persists:

- Confirm the `frontend` container is `Up` and mapped to `0.0.0.0:3000->3000/tcp`.
- Confirm `frontend/.env.development` contains `VITE_GLOB_API_URL=/api/v1`.
- Confirm `docker-compose.prod.yml` still maps `ports: ["3000:3000"]` for the `frontend` service and enables it with the `dev` profile.
- Confirm `HOST=0.0.0.0` is present in the `frontend` service environment.

## API Gateway Checks

The gateway should answer on the host at `http://localhost:8080` and forward `/api/v1/*` into PHP-FPM.

Check the gateway logs:

```bash
docker compose -f docker-compose.prod.yml logs -f nginx
```

Expected request targets:

- `/api/v1/auth/login`
- `/api/v1/user/info`
- `/api/v1/auth/user`

If the frontend cannot reach the API, verify the Vite proxy target resolves to `http://nginx:80` inside Docker.

## Reset Local Stack

If the stack got wedged during a broken startup, reset volumes and start again:

```bash
docker compose -f docker-compose.prod.yml down --volumes
```
