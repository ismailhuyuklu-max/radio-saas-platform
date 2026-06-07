# Faz CTO-16 — PgBouncer transaction pooler.
# Lightweight conn-pooling; PHP-FPM her request'te yeni PG conn açıyor
# → PgBouncer cached pool ile ~50-100ms latency tasarrufu.
#
# upstream: edoburu/pgbouncer (Alpine, ~25 MB).
FROM edoburu/pgbouncer:1.23.1

# Healthcheck için psql + pgbouncer admin shell araçlarını ekle
USER root
RUN apk add --no-cache postgresql16-client \
    && chown -R postgres:postgres /etc/pgbouncer
USER postgres
