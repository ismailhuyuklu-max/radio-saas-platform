<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\MatrixRepository;
use RadioSaaS\Repository\RegionRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Service\MediaFeedService;
use RadioSaaS\Service\AdminAuthenticator;

final class MatrixController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly MatrixRepository $matrixRepository,
        private readonly RegionRepository $regionRepository,
        private readonly StationRepository $stationRepository,
        private readonly MediaFeedService $feedService,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function regions(): void
    {
        $this->guard('matrix:view');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->matrixRepository->listRegions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function matrix(): void
    {
        $this->guard('matrix:view');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->matrixRepository->buildMatrix(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function assignSponsor(): void
    {
        $this->guard('sponsors:write');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $payload = str_contains($contentType, 'application/json')
            ? json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR)
            : $_POST;

        if (isset($payload['target_regions']) && is_string($payload['target_regions'])) {
            $payload['target_regions'] = json_decode($payload['target_regions'], true, 512, JSON_THROW_ON_ERROR);
        }

        if (isset($payload['target_parts']) && is_string($payload['target_parts'])) {
            $payload['target_parts'] = json_decode($payload['target_parts'], true, 512, JSON_THROW_ON_ERROR);
        }

        $targetRegions = is_array($payload['target_regions'] ?? null) ? $payload['target_regions'] : [];
        $targetParts = is_array($payload['target_parts'] ?? null) ? $payload['target_parts'] : [];

        $targetRegions = $targetRegions !== [] ? $targetRegions : [$payload['region_id'] ?? null];
        $targetParts = $targetParts !== [] ? $targetParts : [$payload['part_code'] ?? null];
        $payload['placement_type'] = $payload['placement_type']
            ?? (($payload['placement'] ?? 'pre_roll') === 'post_roll' ? 'outro' : 'intro');
        $payload['is_global'] = array_key_exists('is_global', $payload)
            ? $this->readBool($payload['is_global'])
            : count($targetRegions) >= 7;
        $payload['content_type'] = $payload['content_type'] ?? ($payload['part_code'] ?? 'news');
        $payload['sponsor_name'] = $payload['sponsor_name'] ?? ($payload['name'] ?? null);

        if (in_array(null, $targetRegions, true) || in_array(null, $targetParts, true)) {
            throw new \RuntimeException('Sponsor region or part could not be resolved.');
        }

        if (!is_string($payload['sponsor_name']) || trim($payload['sponsor_name']) === '') {
            throw new \RuntimeException('Sponsor name could not be resolved.');
        }

        $sponsorIds = [];
        $regionsToPersist = $payload['is_global'] ? [$targetRegions[0]] : $targetRegions;
        foreach ($targetParts as $partCode) {
            foreach ($regionsToPersist as $regionCode) {
                if (!is_string($regionCode) || !is_string($partCode)) {
                    throw new \RuntimeException('Sponsor region or part could not be resolved.');
                }

                $resolvedRegionId = $this->regionRepository->findIdByCode($regionCode);
                if ($resolvedRegionId === null && preg_match('/^[0-9a-f-]{36}$/i', $regionCode)) {
                    $resolvedRegionId = $regionCode;
                }
                if ($resolvedRegionId === null) {
                    throw new \RuntimeException('Sponsor region or part could not be resolved.');
                }

                $itemPayload = array_merge($payload, [
                    'region_id' => $resolvedRegionId,
                    'part_code' => $partCode,
                    'content_type' => $partCode,
                ]);
                $sponsorId = $this->matrixRepository->insertSponsor($itemPayload);
                $this->auditLogRepository->log('admin', 'assign_sponsor', 'sponsor', $sponsorId, $itemPayload);
                $sponsorIds[] = $sponsorId;
            }
        }

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sponsor_id' => $sponsorIds[0] ?? null,
            'sponsor_ids' => $sponsorIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function sponsors(): void
    {
        $this->guard('sponsors:view');

        $rows = $this->matrixRepository->listSponsors($_GET['limit'] ?? null, $_GET['offset'] ?? null);
        $out = array_map(static function (array $r): array {
            $placement = (string) ($r['placement'] ?? 'pre_roll');

            return [
                'id' => (string) $r['id'],
                'sponsor_name' => (string) ($r['sponsor_name'] ?? ''),
                'region_code' => (string) ($r['region_code'] ?? ''),
                'region_name' => (string) ($r['region_name'] ?? ''),
                'part_code' => (string) ($r['part_code'] ?? ''),
                'content_type' => (string) ($r['content_type'] ?? $r['part_code'] ?? ''),
                'placement' => $placement,
                'placement_type' => (string) ($r['placement_type'] ?? ($placement === 'post_roll' ? 'outro' : 'intro')),
                'is_global' => filter_var($r['is_global'] ?? false, FILTER_VALIDATE_BOOL),
                'asset_bucket' => (string) ($r['asset_bucket'] ?? ''),
                'asset_key' => (string) ($r['asset_key'] ?? ''),
                'asset_mime' => (string) ($r['asset_mime'] ?? ''),
                'asset_duration_ms' => (int) ($r['asset_duration_ms'] ?? 0),
                'priority' => (int) ($r['priority'] ?? 100),
                'is_active' => filter_var($r['is_active'] ?? true, FILTER_VALIDATE_BOOL),
                'starts_at' => $r['starts_at'] ?? null,
                'ends_at' => $r['ends_at'] ?? null,
            ];
        }, $rows);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function deleteSponsor(string $id): void
    {
        $this->guard('sponsors:write');

        $this->matrixRepository->deleteSponsor($id);
        $this->auditLogRepository->log('admin', 'delete', 'sponsor', $id, []);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['deleted' => true, 'sponsor_id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function refresh(): void
    {
        $this->guard('matrix:refresh');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['refreshed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function live(): void
    {
        $this->guard('matrix:view');

        $regionCode = (string) ($_GET['region'] ?? 'marmara');
        $region = $this->regionRepository->findByCode($regionCode);

        if ($region === null) {
            throw new \RuntimeException('Region not found.');
        }

        $activeStations = [];
        $stationRows = $this->stationRepository->listActiveByRegion($regionCode);
        foreach ($stationRows as $station) {
            try {
                $feed = $this->feedService->resolve((string) $station['slug'], 'news');
                $activeStations[] = [
                    'id' => (string) $station['id'],
                    'name' => (string) $station['name'],
                    'slug' => (string) $station['slug'],
                    'city_name' => (string) ($station['city_name'] ?? $station['name']),
                    'region_code' => (string) $station['region_code'],
                    'region_name' => (string) $station['region_name'],
                    'is_active' => (bool) ($station['is_active'] ?? false),
                    'status' => (string) ($station['status'] ?? 'active'),
                    'stream_token' => (string) ($station['stream_token'] ?? ''),
                    'feed_url' => (string) ($feed['stream']['download_url'] ?? ''),
                    'feed_mime' => (string) ($feed['stream']['mime'] ?? 'audio/mpeg'),
                    'updated_at' => (string) ($feed['media']['rendered_generated_at'] ?? $feed['media']['published_at'] ?? ''),
                ];
            } catch (\Throwable) {
                $activeStations[] = [
                    'id' => (string) $station['id'],
                    'name' => (string) $station['name'],
                    'slug' => (string) $station['slug'],
                    'city_name' => (string) ($station['city_name'] ?? $station['name']),
                    'region_code' => (string) $station['region_code'],
                    'region_name' => (string) $station['region_name'],
                    'is_active' => (bool) ($station['is_active'] ?? false),
                    'status' => (string) ($station['status'] ?? 'active'),
                    'stream_token' => (string) ($station['stream_token'] ?? ''),
                    'feed_url' => '',
                    'feed_mime' => 'audio/mpeg',
                    'updated_at' => null,
                ];
            }
        }

        $slotTimes = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
        $slotStatus = empty($activeStations) ? 'danger' : 'success';
        $latestUpdate = null;
        foreach ($activeStations as $station) {
            if (!empty($station['updated_at'])) {
                $latestUpdate = $station['updated_at'];
                break;
            }
        }

        $slots = array_map(
            fn (string $slotTime): array => [
                'time' => $slotTime,
                'part_code' => 'news',
                'part_label' => 'Haber',
                'status' => $slotStatus,
                'station_count' => count($activeStations),
                'station_names' => array_map(static fn (array $station): string => $station['name'], $activeStations),
                'feed_urls' => array_map(static fn (array $station): string => $station['feed_url'], $activeStations),
                'updated_at' => $latestUpdate,
            ],
            $slotTimes
        );

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'region' => [
                'code' => (string) $region['code'],
                'name' => (string) $region['name'],
            ],
            'slots' => $slots,
            'active_stations' => $activeStations,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function guard(string $permission): void
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }

        $this->authenticator->authorize($token, $permission);
    }

    private function readBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
