<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use PDO;
use Throwable;

/**
 * Faz H4-2 — Deep /healthz endpoint (no auth, orchestrator-friendly).
 *
 * docker/k8s/Caddy + nginx healthcheck ve insan operatör için tek nokta:
 *   GET /api/v1/healthz/deep
 *
 * Şunları kontrol eder:
 *   - PostgreSQL ping (SELECT 1) — bağlantı + latency
 *   - MinIO /minio/health/live — endpoint erişimi
 *   - Disk free yüzdesi (/tmp / / var) — eşik altına düşerse uyarır
 *
 * HTTP status mapping (orchestrator kontratı):
 *   200 ok       → tüm bileşenler up
 *   200 degraded → uyarılı (disk %85 dolu, vb.) — trafik kesilmez
 *   503 down     → en az bir kritik bileşen ölü — orchestrator restart edebilir
 *
 * GÜVENLİK NOTU: yanıt body'si sürüm/secret bilgisi sızdırmaz; sadece
 * bileşen adı + 'up/degraded/down' + latency_ms.
 */
final class HealthController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function deep(): void
    {
        $started = microtime(true);

        $checks = [
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'disk' => $this->checkDisk(),
        ];

        $overall = 'ok';
        $httpStatus = 200;
        foreach ($checks as $c) {
            if (($c['status'] ?? '') === 'down') {
                $overall = 'down';
                $httpStatus = 503;
                break;
            }
            if (($c['status'] ?? '') === 'degraded' && $overall === 'ok') {
                $overall = 'degraded';
                // 200 ile cevap ver — orchestrator restart etmemeli, sadece
                // monitoring panosu uyarı göstermeli.
            }
        }

        $body = [
            'status' => $overall,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'checks' => $checks,
            'checked_at' => date('c'),
        ];

        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // -- checks -----------------------------------------------------------

    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            $val = (int) $this->pdo->query('SELECT 1')->fetchColumn();
            if ($val !== 1) {
                return ['status' => 'down', 'detail' => 'unexpected response'];
            }
            $ms = (int) round((microtime(true) - $start) * 1000);
            $status = $ms > 500 ? 'degraded' : 'up';
            return ['status' => $status, 'latency_ms' => $ms];
        } catch (Throwable $e) {
            return ['status' => 'down', 'detail' => 'connection failed'];
        }
    }

    private function checkStorage(): array
    {
        $endpoint = rtrim((string) (getenv('MINIO_ENDPOINT') ?: 'http://minio:9000'), '/');
        $start = microtime(true);
        $ch = curl_init($endpoint . '/minio/health/live');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        @curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch) ?: null;
        curl_close($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($code >= 200 && $code < 400) {
            return ['status' => $ms > 1500 ? 'degraded' : 'up', 'latency_ms' => $ms];
        }
        return ['status' => 'down', 'detail' => $err ?? "http {$code}"];
    }

    private function checkDisk(): array
    {
        $path = '/tmp';
        $free = (float) (@disk_free_space($path) ?: 0);
        $total = (float) (@disk_total_space($path) ?: 0);
        if ($total <= 0) {
            return ['status' => 'degraded', 'detail' => 'disk metrics unavailable'];
        }
        $freePct = ($free / $total) * 100;
        $usedPct = 100 - $freePct;

        // Üretim mantıklı eşikler — render queue + log + temp upload yer açar.
        if ($freePct < 5) {
            $status = 'down';
        } elseif ($freePct < 15) {
            $status = 'degraded';
        } else {
            $status = 'up';
        }

        return [
            'status' => $status,
            'free_pct' => round($freePct, 1),
            'used_pct' => round($usedPct, 1),
            'free_mb' => (int) round($free / 1024 / 1024),
            'total_mb' => (int) round($total / 1024 / 1024),
            'path' => $path,
        ];
    }
}
