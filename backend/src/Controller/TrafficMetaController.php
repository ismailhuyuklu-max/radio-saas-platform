<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\ProvinceRepository;
use RadioSaaS\Repository\StationGroupRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RuntimeException;

/**
 * Targeting metadata for the traffic center: the 81 provinces, radio groups and
 * the station list operators pick from when building a national plan.
 */
final class TrafficMetaController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly ProvinceRepository $provinceRepository,
        private readonly StationGroupRepository $stationGroupRepository,
        private readonly StationRepository $stationRepository
    ) {
    }

    public function provinces(): void
    {
        $this->guard('plans:view');
        // Faz CTO-20: ETag + 304. 81 il statik veri — yüksek 304 hit oranı.
        $body = [
            'code' => 0,
            'result' => ['provinces' => $this->provinceRepository->listAll()],
            'message' => 'Success',
        ];
        if (\RadioSaaS\Service\EtagCache::checkBody($body)) return;
        $this->json($body);
    }

    public function groups(): void
    {
        $this->guard('plans:view');
        $groups = $this->stationGroupRepository->listAll();
        foreach ($groups as &$group) {
            $group['station_ids'] = $this->stationGroupRepository->memberStationIds((string) $group['id']);
        }
        unset($group);
        $body = [
            'code' => 0,
            'result' => ['groups' => $groups],
            'message' => 'Success',
        ];
        if (\RadioSaaS\Service\EtagCache::checkBody($body)) return;
        $this->json($body);
    }

    public function createGroup(): void
    {
        $this->guard('plans:write');
        $payload = $this->readJsonPayload();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Grup adı gerekli.');
        }
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
        $group = $this->stationGroupRepository->create($name, $description === '' ? null : $description);

        $stationIds = $payload['station_ids'] ?? [];
        if (is_array($stationIds)) {
            $this->stationGroupRepository->setMembers((string) $group['id'], array_map('strval', $stationIds));
        }
        $group['station_ids'] = $this->stationGroupRepository->memberStationIds((string) $group['id']);

        http_response_code(201);
        $this->json(['code' => 0, 'result' => $group, 'message' => 'Success']);
    }

    public function updateGroupMembers(string $groupId): void
    {
        $this->guard('plans:write');
        $payload = $this->readJsonPayload();
        $stationIds = $payload['station_ids'] ?? [];
        if (!is_array($stationIds)) {
            $stationIds = [];
        }
        $this->stationGroupRepository->setMembers($groupId, array_map('strval', $stationIds));
        $this->json([
            'code' => 0,
            'result' => ['station_ids' => $this->stationGroupRepository->memberStationIds($groupId)],
            'message' => 'Success',
        ]);
    }

    public function deleteGroup(string $groupId): void
    {
        $this->guard('plans:write');
        $this->stationGroupRepository->delete($groupId);
        $this->json(['code' => 0, 'result' => ['deleted' => true], 'message' => 'Success']);
    }

    public function stations(): void
    {
        $this->guard('plans:view');
        $stations = $this->stationRepository->listStations();
        $slim = array_map(static fn (array $s): array => [
            'id' => (string) $s['id'],
            'name' => (string) ($s['name'] ?? ''),
            'slug' => (string) ($s['slug'] ?? ''),
            'city_name' => $s['city_name'] ?? null,
            'region_id' => (string) ($s['region_id'] ?? ''),
            'region_code' => $s['region_code'] ?? null,
            'group_id' => $s['group_id'] ?? null,
        ], $stations);
        // Faz CTO-20: ETag + 304 cache
        $body = [
            'code' => 0,
            'result' => ['stations' => $slim],
            'message' => 'Success',
        ];
        if (\RadioSaaS\Service\EtagCache::checkBody($body)) return;
        $this->json($body);
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

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
