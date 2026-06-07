<?php

declare(strict_types=1);

namespace RadioSaaS\Infrastructure;

use PDO;
use PDOException;

final class PdoFactory
{
    public static function fromEnv(): PDO
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'radio_saas';
        $user = getenv('DB_USER') ?: 'radio_saas';
        $password = getenv('DB_PASSWORD') ?: 'radio_saas_password';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Faz CTO-16: PgBouncer transaction mode native prepared statement
            // desteklemez. PDO emulated prepares (driver-side) tüm PgBouncer
            // pool mode'larıyla uyumlu. PG planner her execute'da plan yapar
            // ama PgBouncer pool kazanımı (50-100 ms tasarruf) ağır basar.
            // Direkt PG'ye bağlananlar için false yapmak istenirse env override:
            //   DB_EMULATE_PREPARES=0
            PDO::ATTR_EMULATE_PREPARES => (getenv('DB_EMULATE_PREPARES') ?? '1') !== '0',
        ];

        // Faz H5-2 — Slow query log opt-in. DB_SLOW_QUERY_MS=200 set
        // edilirse PDO subclass döner; aksi halde stock PDO (sıfır overhead).
        $slowMs = (int) (getenv('DB_SLOW_QUERY_MS') ?: 0);

        try {
            if ($slowMs > 0) {
                $pdo = new LoggingPdo($dsn, $user, $password, $options, $slowMs);
            } else {
                $pdo = new PDO($dsn, $user, $password, $options);
            }
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $pdo;
    }
}
