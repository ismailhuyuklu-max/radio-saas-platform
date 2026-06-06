<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Repository\ProvinceRepository;
use RadioSaaS\Repository\RegionRepository;
use RadioSaaS\Repository\StationGroupRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\TrafficPlanner;
use RuntimeException;

final class PlanningController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly ContentPlanRepository $planRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly RegionRepository $regionRepository,
        private readonly ?StationRepository $stationRepository = null,
        private readonly ?ProvinceRepository $provinceRepository = null,
        private readonly ?StationGroupRepository $stationGroupRepository = null
    ) {
    }

    /**
     * Traffic-center bulk planner: target (regions/stations) × slots × dates,
     * created in one transaction with conflict reporting. Powers one-click
     * "Tüm Türkiye / 7 gün / Sabah Haber Kuşağı" style planning.
     */
    public function bulkStore(): void
    {
        $this->guard('plans:write');
        $payload = $this->readJsonPayload();

        $regionCodes = $this->asArray($payload['target_regions'] ?? []);
        $provinceNames = $this->asArray($payload['target_provinces'] ?? []);
        $stationIds = $this->asArray($payload['station_ids'] ?? []);
        $groupIds = $this->asArray($payload['group_ids'] ?? []);
        $slots = $this->asArray($payload['slots'] ?? []);
        $startDate = (string) ($payload['start_date'] ?? date('Y-m-d'));
        $repeatDays = (int) ($payload['repeat_days'] ?? 1);
        $campaignId = trim((string) ($payload['campaign_id'] ?? ''));
        $campaignId = $campaignId === '' ? null : $campaignId;

        if ($slots === []) {
            throw new RuntimeException('En az bir yayın kuşağı (slot) gerekli.');
        }

        // Build targets across four scopes. Each target carries an optional
        // province (il-level) so the conflict engine keys per-il, and an
        // optional station_id (station-level, conflict check skipped).
        $targets = [];

        // Tüm Türkiye / Bölge scope — region-wide, no province.
        foreach ($regionCodes as $code) {
            $rid = $this->resolveRegionId((string) $code);
            if ($rid !== null) {
                $targets[] = [
                    'region_id' => $rid,
                    'region_code' => (string) $code,
                    'province' => null,
                    'station_id' => null,
                ];
            }
        }

        // İl scope — resolve each province to its region, key plan to that il.
        foreach ($provinceNames as $name) {
            $name = (string) $name;
            $regionCode = $this->provinceRepository?->regionForProvince($name);
            if ($regionCode === null) {
                continue;
            }
            $rid = $this->resolveRegionId($regionCode);
            if ($rid !== null) {
                $targets[] = [
                    'region_id' => $rid,
                    'region_code' => $regionCode,
                    'province' => $name,
                    'station_id' => null,
                ];
            }
        }

        // Radyo Grubu scope — expand each group into its member stations.
        $expandedStationIds = $stationIds;
        foreach ($groupIds as $gid) {
            $members = $this->stationGroupRepository?->memberStationIds((string) $gid) ?? [];
            $expandedStationIds = array_merge($expandedStationIds, $members);
        }

        // Radyo scope — station-specific plans.
        $seenStations = [];
        foreach ($expandedStationIds as $sid) {
            $sid = (string) $sid;
            if (isset($seenStations[$sid])) {
                continue;
            }
            $seenStations[$sid] = true;
            $station = $this->stationRepository?->findById($sid);
            if ($station !== null) {
                $targets[] = [
                    'region_id' => (string) $station['region_id'],
                    'region_code' => (string) ($station['region_code'] ?? ''),
                    'province' => $station['city_name'] ?? null,
                    'station_id' => $sid,
                ];
            }
        }

        if ($targets === []) {
            throw new RuntimeException('Geçerli bir hedef (bölge/il/grup/istasyon) seçilmedi.');
        }

        $dates = TrafficPlanner::expandDates($startDate, $repeatDays);
        $specs = TrafficPlanner::buildSpecs($targets, $slots, $dates);

        $created = 0;
        $skipped = 0;
        $conflicts = [];
        foreach ($specs as $spec) {
            $plan = [
                'region_id' => (string) $spec['region_id'],
                'station_id' => $spec['station_id'] ?? null,
                'province' => $spec['province'] ?? null,
                'campaign_id' => $campaignId,
                'part_code' => (string) ($spec['part_code'] ?? 'news'),
                'slot_time' => (string) ($spec['slot_time'] ?? '08:00'),
                'plan_date' => (string) $spec['plan_date'],
                'content_title' => (string) ($spec['content_title'] ?? 'Yayın'),
                'content_kind' => (string) ($spec['part_code'] ?? 'news'),
                'status' => (string) ($spec['status'] ?? 'published'),
                'is_global' => false,
                'target_regions' => [$spec['region_code'] ?? ''],
                'target_parts' => [$spec['part_code'] ?? 'news'],
                'created_by' => 'admin',
            ];
            // Station-level plans are per-station so the region/il conflict check
            // is skipped; region- and il-level plans must not double-book a slot.
            if ($plan['station_id'] === null && $this->planRepository->hasConflict($plan)) {
                $skipped++;
                if (count($conflicts) < 25) {
                    $label = ($spec['province'] ?? null) ?: ($spec['region_code'] ?? '');
                    $conflicts[] = $plan['slot_time'] . ' · ' . $label . ' · ' . $plan['plan_date'];
                }
                continue;
            }
            $this->planRepository->upsert($plan);
            $created++;
        }

        $this->auditLogRepository->log('admin', 'bulk_plan', 'content_plan', null, [
            'created' => $created,
            'skipped' => $skipped,
            'targets' => count($targets),
            'slots' => count($slots),
            'days' => count($dates),
        ]);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 0,
            'result' => [
                'created' => $created,
                'skipped' => $skipped,
                'total' => count($specs),
                'conflicts' => $conflicts,
            ],
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<mixed> */
    private function asArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return is_array($value) ? array_values($value) : [];
    }

    public function index(): void
    {
        $this->guard('plans:view');
        $filters = [
            'date' => $_GET['date'] ?? date('Y-m-d'),
            'region' => $_GET['region'] ?? null,
            'status' => $_GET['status'] ?? null,
        ];

        $plans = $this->planRepository->listPlans($filters);
        $calendar = $this->planRepository->listCalendar($filters);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'plans' => $plans,
            'calendar' => $calendar,
            'filters' => $filters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function store(): void
    {
        $this->guard('plans:write');
        $payload = $this->readJsonPayload();
        $validated = $this->normalizePayload($payload);
        if (empty($validated['region_id'])) {
            throw new RuntimeException('Region is required.');
        }
        $this->assertNoConflict($validated);
        $plan = $this->planRepository->upsert($validated);
        $this->auditLogRepository->log('admin', 'create', 'content_plan', (string) ($plan['id'] ?? ''), $plan);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 0,
            'result' => $plan,
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function update(string $planId): void
    {
        $this->guard('plans:write');
        $existing = $this->planRepository->findById($planId);
        if ($existing === null) {
            throw new RuntimeException('Plan not found.');
        }

        $payload = array_merge($existing, $this->normalizePayload($this->readJsonPayload()));
        $payload['id'] = $planId;
        $this->assertNoConflict($payload, $planId);
        $plan = $this->planRepository->upsert($payload);
        $this->auditLogRepository->log('admin', 'update', 'content_plan', $planId, $plan);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 0,
            'result' => $plan,
            'message' => 'Success',
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

    private function readJsonPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }

    private function normalizePayload(array $payload): array
    {
        $targetRegions = $payload['target_regions'] ?? [];
        if (is_string($targetRegions)) {
            $decoded = json_decode($targetRegions, true);
            $targetRegions = is_array($decoded) ? $decoded : [];
        }

        $targetParts = $payload['target_parts'] ?? [];
        if (is_string($targetParts)) {
            $decoded = json_decode($targetParts, true);
            $targetParts = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $payload['id'] ?? null,
            'region_id' => $this->resolveRegionId($payload['region_id'] ?? $payload['regionId'] ?? null),
            'station_id' => $payload['station_id'] ?? $payload['stationId'] ?? null,
            'part_code' => $payload['part_code'] ?? $payload['partCode'] ?? 'news',
            'slot_time' => $payload['slot_time'] ?? $payload['slotTime'] ?? '08:00',
            'plan_date' => $payload['plan_date'] ?? $payload['planDate'] ?? date('Y-m-d'),
            'content_title' => $payload['content_title'] ?? $payload['title'] ?? 'Yeni içerik',
            'content_kind' => $payload['content_kind'] ?? $payload['contentType'] ?? ($payload['part_code'] ?? 'news'),
            'status' => $payload['status'] ?? 'draft',
            'is_global' => $this->readBool($payload['is_global'] ?? false),
            'target_regions' => $targetRegions,
            'target_parts' => $targetParts,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $payload['created_by'] ?? 'admin',
        ];
    }

    private function resolveRegionId(?string $region): ?string
    {
        $region = $region !== null ? trim($region) : '';
        if ($region === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f-]{36}$/i', $region)) {
            return $region;
        }

        return $this->regionRepository->findIdByCode($region);
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

    private function assertNoConflict(array $payload, ?string $ignoreId = null): void
    {
        if ($this->planRepository->hasConflict($payload, $ignoreId)) {
            throw new RuntimeException('Bu saat kuşağında aynı bölge için çakışan plan mevcut.');
        }
    }
}
