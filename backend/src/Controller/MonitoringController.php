<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use PDO;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\MetricsService;
use Throwable;

/**
 * NOC — service health (Faz 5) + live CPU/RAM/Disk metrics (Faz 6).
 */
final class MonitoringController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly PDO $pdo
    ) {
    }

    public function health(): void
    {
        $this->guard('monitoring:view');

        $services = [
            $this->checkDatabase(),
            $this->checkStorage(),
            $this->checkQueue(),
            $this->checkWorker(),
        ];

        $overall = 'up';
        foreach ($services as $service) {
            if ($service['status'] === 'down') {
                $overall = 'down';
                break;
            }
            if ($service['status'] === 'degraded') {
                $overall = 'degraded';
            }
        }

        $this->respond([
            'overall' => $overall,
            'services' => $services,
            'checked_at' => date('c'),
        ]);
    }

    public function metrics(): void
    {
        $this->guard('monitoring:view');
        $this->respond([
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
            'load' => $this->load(),
            'sampled_at' => date('c'),
        ]);
    }

    // --- service checks -------------------------------------------------------

    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $latency = (int) round((microtime(true) - $start) * 1000);
            return $this->service('database', 'PostgreSQL', 'up', "{$latency} ms yanıt", $latency);
        } catch (Throwable $e) {
            return $this->service('database', 'PostgreSQL', 'down', 'Bağlantı yok');
        }
    }

    private function checkStorage(): array
    {
        $endpoint = rtrim(getenv('MINIO_ENDPOINT') ?: 'http://minio:9000', '/');
        $start = microtime(true);
        $ch = curl_init($endpoint . '/minio/health/live');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($code >= 200 && $code < 400) {
            return $this->service('storage', 'MinIO (S3)', 'up', "{$latency} ms yanıt", $latency);
        }
        return $this->service('storage', 'MinIO (S3)', 'down', 'Sağlık kontrolü başarısız');
    }

    private function checkQueue(): array
    {
        try {
            $counts = $this->jobCounts();
            $failed = $counts['failed'] ?? 0;
            $pending = $counts['pending'] ?? 0;
            $status = $failed > 0 ? 'degraded' : 'up';
            $detail = "{$pending} bekleyen · {$failed} hatalı";
            return $this->service('queue', 'Render Kuyruğu', $status, $detail, null, $counts);
        } catch (Throwable $e) {
            return $this->service('queue', 'Render Kuyruğu', 'down', 'Kuyruk okunamadı');
        }
    }

    private function checkWorker(): array
    {
        try {
            $stuck = (int) $this->pdo->query(
                "SELECT count(*) FROM media_jobs
                 WHERE status = 'processing' AND locked_at < now() - interval '10 minutes'"
            )->fetchColumn();

            $last = $this->pdo->query(
                "SELECT max(updated_at) FROM media_jobs WHERE status = 'completed'"
            )->fetchColumn();

            $status = $stuck > 0 ? 'degraded' : 'up';
            $detail = $stuck > 0
                ? "{$stuck} iş takıldı (>10dk)"
                : ($last ? 'Son iş: ' . date('H:i', strtotime((string) $last)) : 'Boşta');
            return $this->service('worker', 'Render Worker', $status, $detail);
        } catch (Throwable $e) {
            return $this->service('worker', 'Render Worker', 'down', 'Durum alınamadı');
        }
    }

    /** @return array<string,int> */
    private function jobCounts(): array
    {
        $rows = $this->pdo->query('SELECT status, count(*) AS c FROM media_jobs GROUP BY status')->fetchAll();
        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    // --- metrics --------------------------------------------------------------

    private function cpu(): array
    {
        $first = @file_get_contents('/proc/stat');
        if ($first === false) {
            return ['usage_pct' => null, 'cores' => $this->cores(), 'tone' => 'ok'];
        }
        $s1 = MetricsService::parseCpuStat($first);
        usleep(200_000);
        $s2 = MetricsService::parseCpuStat((string) @file_get_contents('/proc/stat'));
        $usage = MetricsService::cpuUsagePct($s1, $s2);
        return ['usage_pct' => $usage, 'cores' => $this->cores(), 'tone' => MetricsService::tone($usage)];
    }

    private function memory(): array
    {
        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return ['used_pct' => null, 'total_kb' => 0, 'used_kb' => 0, 'tone' => 'ok'];
        }
        $mem = MetricsService::parseMeminfo($contents);
        $mem['tone'] = MetricsService::tone($mem['used_pct']);
        return $mem;
    }

    private function disk(): array
    {
        $free = (float) (@disk_free_space('/') ?: 0);
        $total = (float) (@disk_total_space('/') ?: 0);
        $disk = MetricsService::diskUsage($free, $total);
        $disk['tone'] = MetricsService::tone($disk['used_pct']);
        return $disk;
    }

    private function load(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
        return [
            '1m' => round((float) ($load[0] ?? 0), 2),
            '5m' => round((float) ($load[1] ?? 0), 2),
            '15m' => round((float) ($load[2] ?? 0), 2),
        ];
    }

    private function cores(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo === false) {
            return 1;
        }
        return max(1, substr_count($cpuinfo, 'processor'));
    }

    // --- helpers --------------------------------------------------------------

    private function service(string $key, string $label, string $status, string $detail, ?int $latencyMs = null, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'latency_ms' => $latencyMs,
        ], $extra ? ['meta' => $extra] : []);
    }

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function guard(string $permission): void
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }
        $this->authenticator->authorize($token, $permission);
    }
}
