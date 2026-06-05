<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AdCampaignRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\ReportService;
use RadioSaaS\Service\RevenueService;
use RuntimeException;

/**
 * Faz 10 — multi-format reporting (CSV / XLSX / PDF).
 *
 * Builds a (title, headers, rows) dataset per report type from the existing
 * repositories, then streams it in the requested format via ReportService.
 */
final class ReportController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly AdCampaignRepository $campaignRepository,
        private readonly ContentPlanRepository $planRepository,
        private readonly StationRepository $stationRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function export(string $type): void
    {
        $this->guard('reports:view');
        $format = strtolower((string) ($_GET['format'] ?? 'csv'));
        if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            throw new RuntimeException('Unsupported report format.');
        }

        [$title, $headers, $rows] = $this->dataset($type);
        $this->auditLogRepository->log('admin', 'export_report', 'report', $type, ['format' => $format]);

        $filename = 'rapor-' . $type . '-' . date('Ymd') . '.' . $format;
        match ($format) {
            'csv' => $this->stream(
                ReportService::toCsv($headers, $rows),
                'text/csv; charset=utf-8',
                $filename
            ),
            'xlsx' => $this->stream(
                ReportService::toXlsx($headers, $rows, $title),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $filename
            ),
            'pdf' => $this->stream(
                ReportService::toPdf($title, $headers, $rows),
                'application/pdf',
                $filename
            ),
        };
    }

    /**
     * @return array{0:string,1:list<string>,2:list<list<scalar|null>>}
     */
    private function dataset(string $type): array
    {
        return match ($type) {
            'revenue' => $this->revenueDataset(),
            'broadcast' => $this->broadcastDataset(),
            'stations' => $this->stationsDataset(),
            default => throw new RuntimeException('Unknown report type.'),
        };
    }

    private function revenueDataset(): array
    {
        $today = date('Y-m-d');
        $campaigns = $this->campaignRepository->listAll();
        $rows = [];
        foreach ($campaigns as $c) {
            $m = RevenueService::computeCampaign($c, $today);
            $rows[] = [
                (string) ($c['advertiser_name'] ?? ''),
                strtoupper((string) ($c['pricing_model'] ?? '')),
                (string) ($c['status'] ?? ''),
                (float) ($c['budget'] ?? 0),
                $m['delivered_revenue'],
                $m['projected_revenue'],
                $m['delivered_impressions'],
            ];
        }
        return [
            'Gelir Raporu',
            ['Reklamveren', 'Model', 'Durum', 'Bütçe', 'Gerçekleşen Gelir', 'Projeksiyon Gelir', 'Gösterim'],
            $rows,
        ];
    }

    private function broadcastDataset(): array
    {
        $date = (string) ($_GET['date'] ?? date('Y-m-d'));
        $plans = $this->planRepository->listPlans(['date' => $date]);
        $rows = [];
        foreach ($plans as $p) {
            $rows[] = [
                (string) ($p['region_name'] ?? $p['region_code'] ?? ''),
                substr((string) ($p['slot_time'] ?? ''), 0, 5),
                (string) ($p['part_code'] ?? ''),
                (string) ($p['content_title'] ?? ''),
                (string) ($p['status'] ?? ''),
            ];
        }
        return [
            'Yayın Akışı Raporu (' . $date . ')',
            ['Bölge', 'Saat', 'Tür', 'İçerik', 'Durum'],
            $rows,
        ];
    }

    private function stationsDataset(): array
    {
        $stations = $this->stationRepository->listStations([]);
        $rows = [];
        foreach ($stations as $s) {
            $rows[] = [
                (string) ($s['name'] ?? ''),
                (string) ($s['region_name'] ?? $s['region_code'] ?? ''),
                (string) ($s['city_name'] ?? ''),
                ($s['is_active'] ?? false) ? 'Aktif' : 'Pasif',
            ];
        }
        return [
            'İstasyon Raporu',
            ['İstasyon', 'Bölge', 'Şehir', 'Durum'],
            $rows,
        ];
    }

    private function stream(string $data, string $contentType, string $filename): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
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
