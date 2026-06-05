#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$ROOT_DIR/frontend"
COMPOSE_FILE="$ROOT_DIR/docker-compose.prod.yml"

log() {
  printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$1"
}

wait_for_container_health() {
  local container_name="$1"
  local timeout_seconds="${2:-120}"
  local waited=0

  while true; do
    local status
    status="$(docker inspect -f '{{.State.Health.Status}}' "$container_name" 2>/dev/null || true)"
    if [[ "$status" == "healthy" ]]; then return 0; fi
    if [[ "$status" == "unhealthy" ]]; then
      echo "Container $container_name became unhealthy." >&2
      return 1
    fi
    if (( waited >= timeout_seconds )); then
      echo "Timed out waiting for $container_name to become healthy." >&2
      return 1
    fi
    sleep 2
    waited=$((waited + 2))
  done
}

for tool in npm docker; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "$tool is required but was not found in PATH." >&2
    exit 1
  fi
done

log "Cleaning previous Docker resources"
docker compose -f "$COMPOSE_FILE" down --volumes --remove-orphans

log "Installing frontend dependencies and building bundle"
cd "$FRONTEND_DIR"
if [[ -f package-lock.json ]]; then npm ci; else npm install; fi
npm run build
cd "$ROOT_DIR"

log "Starting infrastructure containers"
docker compose -f "$COMPOSE_FILE" up --build -d postgres minio minio-init php-fpm worker nginx liquidsoap

log "Waiting for PostgreSQL and MinIO"
wait_for_container_health radio-postgres
wait_for_container_health radio-minio

log "Running database migrations"
docker compose -f "$COMPOSE_FILE" run --rm migrate

log "Waiting for frontend and gateway"
gateway_ready=0
for _ in $(seq 1 30); do
  if curl -fsS http://localhost:8080/ >/dev/null 2>&1 && curl -fsS http://localhost:8080/healthz >/dev/null 2>&1; then
    gateway_ready=1
    break
  fi
  sleep 2
done
if [[ "$gateway_ready" -ne 1 ]]; then
  echo "Frontend and gateway did not become ready." >&2
  exit 1
fi

log "Running backend integration test suite"
docker compose -f "$COMPOSE_FILE" exec -T php-fpm php bin/test-suite.php

log "Local deployment completed"
echo "Frontend and API Gateway: http://localhost:8080"
echo "MinIO API: http://localhost:9000"
echo "MinIO Console: http://localhost:9001"
echo "[SUCCESS] Local stack is ready - Login: admin / 123456"
