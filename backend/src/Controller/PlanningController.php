<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RuntimeException;

final class PlanningController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly ContentPlanRepository $planRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function index(): void
    {
        $this->authenticate();
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
        $this->authenticate();
        $payload = $this->readJsonPayload();
        $validated = $this->normalizePayload($payload);
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
        $this->authenticate();
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

    private function authenticate(): void
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }

        $this->authenticator->authenticate($token);
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
            'region_id' => $payload['region_id'] ?? $payload['regionId'] ?? null,
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
