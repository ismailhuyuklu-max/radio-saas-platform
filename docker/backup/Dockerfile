# Faz H1-4: backup container.
# alpine + pg-client + minio-mc — küçük tek-amaçlı image (~40MB).
FROM alpine:3.19

RUN apk add --no-cache \
    postgresql16-client \
    bash \
    curl \
    ca-certificates \
    tzdata \
    && curl -sSL https://dl.min.io/client/mc/release/linux-amd64/mc -o /usr/local/bin/mc \
    && chmod +x /usr/local/bin/mc

ENV TZ=Europe/Istanbul
ENV BACKUP_INTERVAL_HOURS=24

COPY backup-postgres.sh /usr/local/bin/backup-postgres.sh
RUN chmod +x /usr/local/bin/backup-postgres.sh

# Basit forever-loop: her N saatte bir backup-postgres.sh çalıştır.
# (busybox crond yerine kontrolü bizde tut; failure'da exit 1 → compose
# restart unless-stopped tetiklenir → kuyruğun kaybolmaz.)
CMD ["sh", "-c", "while true; do /usr/local/bin/backup-postgres.sh || echo '[backup] FAILED'; sleep $((${BACKUP_INTERVAL_HOURS} * 3600)); done"]
