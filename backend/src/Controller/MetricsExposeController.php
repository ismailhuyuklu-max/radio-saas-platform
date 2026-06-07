<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use PDO;
use RadioSaaS\Service\Metrics;
use Throwable;

/**
 * Faz H5-1 — Prometheus /metrics exposition.
 *
 * GET /api/v1/metrics  (auth-bypass; scraper IP whitelist'i nginx katmanında.)
 *
 * Sızıntı endişesi: sensitive olmayan agregate sayılar. Yine de
 * production'da nginx config'de `allow 10.0.0.0/8; deny all;` ile
 * scrape network'ünü kısıtlamak şart.
 */
final class MetricsExposeController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function expose(): void
    {
        // Standart Prometheus content-type.
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');

        // Application state metrics.
        Metrics::register(
            'radio_saas_info',
            'gauge',
            'Aircast Pro deployment build info (constant 1)'
        );
        Metrics::gauge('radio_saas_info', 1, [
            'version' => 'h5-1',
            'env' => (string) (getenv('APP_ENV') ?: 'local'),
        ]);

        // Queue depth — kritik operasyonel gösterge.
        try {
            $rows = $this->pdo->query('SELECT status, count(*) AS c FROM media_jobs GROUP BY status')
                ->fetchAll();
            Metrics::register('radio_saas_queue_depth', 'gauge', 'Render queue job count per status');
            foreach ($rows ?: [] as $row) {
                Metrics::gauge(
                    'radio_saas_queue_depth',
                    (int) $row['c'],
                    ['status' => (string) $row['status']]
                );
            }
        } catch (Throwable $e) {
            Metrics::register('radio_saas_queue_query_errors_total', 'counter', 'Queue introspection errors');
            Metrics::counter('radio_saas_queue_query_errors_total', 1);
        }

        // Active station count.
        try {
            $count = (int) $this->pdo->query("SELECT count(*) FROM stations WHERE is_active = true")
                ->fetchColumn();
            Metrics::register('radio_saas_stations_active', 'gauge', 'Active station count');
            Metrics::gauge('radio_saas_stations_active', $count);
        } catch (Throwable) {
            // skip silently — health endpoint reports DB down.
        }

        // Failed auth attempts (last 1h) — security signal.
        try {
            $count = (int) $this->pdo->query(
                "SELECT count(*) FROM audit_logs
                 WHERE action = 'login_failed' AND created_at > now() - interval '1 hour'"
            )->fetchColumn();
            Metrics::register('radio_saas_login_failures_1h', 'gauge', 'Failed login attempts last 1h');
            Metrics::gauge('radio_saas_login_failures_1h', $count);
        } catch (Throwable) {
            // optional metric — skip on legacy schema.
        }

        echo Metrics::render();
    }
}
