<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\ApiTokenRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\RegionRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\RadioCredentialService;
use RadioSaaS\Service\StreamTokenService;
use RuntimeException;
use Throwable;

final class StationController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly StationRepository $stationRepository,
        private readonly ApiTokenRepository $tokenRepository,
        private readonly RegionRepository $regionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ?RadioCredentialService $credentialService = null,
        private readonly ?StreamTokenService $streamTokenService = null
    ) {
    }

    public function index(): void
    {
        $this->guard('stations:view');

        $filters = [
            'keyword' => $_GET['keyword'] ?? null,
            'region' => $_GET['region'] ?? null,
            'status' => $_GET['status'] ?? null,
            'is_active' => isset($_GET['is_active']) ? filter_var($_GET['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null,
            'limit' => $_GET['limit'] ?? null,
            'offset' => $_GET['offset'] ?? null,
        ];

        // Faz H2-2: unified zarf
        $this->respond([
            'code' => 0,
            'result' => $this->stationRepository->listStations($filters),
            'message' => 'Success',
        ]);
    }

    public function store(): void
    {
        $this->guard('stations:write');
        $payload = $this->readJsonPayload();
        $name = trim((string) ($payload['name'] ?? ''));
        $regionCode = trim((string) ($payload['region_code'] ?? $payload['region'] ?? ''));
        $cityName = trim((string) ($payload['city_name'] ?? $payload['city'] ?? ''));

        if ($name === '' || $regionCode === '') {
            throw new RuntimeException('Station name and region are required.');
        }

        $regionId = $this->resolveRegionId($regionCode);
        if ($regionId === null) {
            throw new RuntimeException('Region could not be resolved.');
        }

        $stationId = $this->stationRepository->insert([
            'region_id' => $regionId,
            'name' => $name,
            'slug' => $this->slugify($payload['slug'] ?? $name),
            'status' => (string) ($payload['status'] ?? 'active'),
            'is_active' => $this->readBool($payload['is_active'] ?? null, true),
            'city_name' => $cityName !== '' ? $cityName : $name,
            'stream_token' => $payload['stream_token'] ?? null,
        ]);

        $station = $this->stationRepository->findById($stationId);
        $this->auditLogRepository->log('admin', 'create', 'station', $stationId, $station ?? []);

        // Faz 18: auto-provision the partner user + 8 stream tokens in the
        // same request, unless the caller explicitly opts out with
        // {auto_provision:false}. This realises the master prompt's pledge
        // "Admin yeni radyo eklediğinde sistem otomatik oluşturmalıdır".
        $autoProvision = $this->readBool($payload['auto_provision'] ?? null, true);
        $partner = null;
        $tokens = null;
        if ($autoProvision && $this->credentialService !== null && $this->streamTokenService !== null) {
            try {
                $cred = $this->credentialService->provision($stationId);
                $partner = [
                    'username' => $cred['username'],
                    'one_time_password' => $cred['password'],
                    'user_id' => (string) ($cred['user']['id'] ?? ''),
                ];
                $tokens = $this->streamTokenService->rotate($stationId);
                $this->auditLogRepository->log('admin', 'partner_auto_provision', 'station', $stationId, [
                    'username' => $partner['username'],
                    'token_count' => count($tokens),
                ]);
                // Refresh the station row so the response carries user_id.
                $station = $this->stationRepository->findById($stationId);
            } catch (Throwable $e) {
                // Provisioning is best-effort during station create — the
                // station row is still useful, the admin can re-run
                // provision from the Portal modal. Surface the warning.
                $partner = ['error' => $e->getMessage()];
            }
        }

        $this->respond([
            'code' => 0,
            'result' => [
                'station' => $station,
                'partner' => $partner,
                'tokens' => $tokens,
            ],
            'message' => 'Success',
        ], 201);
    }

    public function update(string $stationId): void
    {
        $this->guard('stations:write');
        $existing = $this->stationRepository->findById($stationId);
        if ($existing === null) {
            throw new RuntimeException('Station not found.');
        }

        $payload = $this->readJsonPayload();
        $name = trim((string) ($payload['name'] ?? $existing['name'] ?? ''));
        $regionCode = trim((string) ($payload['region_code'] ?? $existing['region_code'] ?? ''));
        $cityName = trim((string) ($payload['city_name'] ?? $existing['city_name'] ?? ''));
        $regionId = $this->resolveRegionId($regionCode);

        if ($name === '' || $regionId === null) {
            throw new RuntimeException('Station name and region are required.');
        }

        $updateRow = [
            'region_id' => $regionId,
            'name' => $name,
            'slug' => $this->slugify($payload['slug'] ?? $existing['slug'] ?? $name),
            'status' => (string) ($payload['status'] ?? $existing['status'] ?? 'active'),
            'is_active' => $this->readBool($payload['is_active'] ?? $existing['is_active'] ?? null, true),
            'city_name' => $cityName !== '' ? $cityName : $name,
            'stream_token' => $payload['stream_token'] ?? ($existing['stream_token'] ?? null),
        ];
        if (array_key_exists('national_access', $payload)) {
            $updateRow['national_access'] = $this->readBool($payload['national_access'], false);
        }
        $station = $this->stationRepository->update($stationId, $updateRow);
        $this->auditLogRepository->log('admin', 'update', 'station', $stationId, $station ?? []);

        $this->respond([
            'code' => 0,
            'result' => $station,
            'message' => 'Success',
        ]);
    }

    public function destroy(string $stationId): void
    {
        $this->guard('stations:delete');
        $existing = $this->stationRepository->findById($stationId);
        if ($existing === null) {
            throw new RuntimeException('Station not found.');
        }

        $this->stationRepository->delete($stationId);
        $this->auditLogRepository->log('admin', 'delete', 'station', $stationId, $existing ?? []);
        $this->respond([
            'code' => 0,
            'result' => ['deleted' => true, 'station_id' => $stationId],
            'message' => 'Success',
        ]);
    }

    public function toggle(string $stationId): void
    {
        $this->guard('stations:write');
        $payload = $this->readJsonPayload();
        $isActive = $this->readBool($payload['is_active'] ?? null, true);

        $station = $this->stationRepository->toggleActive($stationId, $isActive);
        if ($station === null) {
            throw new RuntimeException('Station not found.');
        }
        $this->auditLogRepository->log('admin', 'toggle', 'station', $stationId, ['is_active' => $isActive]);

        $this->respond([
            'code' => 0,
            'result' => $station,
            'message' => 'Success',
        ]);
    }

    public function generateToken(string $stationId): void
    {
        $this->guard('stations:write');

        $station = $this->stationRepository->findById($stationId);
        if ($station === null) {
            throw new RuntimeException('Station not found.');
        }

        $token = $this->tokenRepository->createForStation($stationId);
        $this->stationRepository->updateStreamToken($stationId, $token['raw_token']);
        $this->auditLogRepository->log('admin', 'generate_token', 'station', $stationId, ['token_prefix' => $token['prefix']]);

        // Faz H2-2: unified zarf
        $this->respond([
            'code' => 0,
            'result' => [
                'station_id' => $stationId,
                'station_token' => $token['raw_token'],
                'stream_token' => $token['raw_token'],
                'token_prefix' => $token['prefix'],
            ],
            'message' => 'Success',
        ]);
    }

    private function guard(string $permission): void
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }

        $this->authenticator->authorize($token, $permission);
    }

    private function resolveRegionId(string $regionCode): ?string
    {
        if ($regionCode === '') {
            return null;
        }

        $region = $this->regionRepository->findByCode($regionCode);
        if ($region !== null) {
            return (string) $region['id'];
        }

        if (preg_match('/^[0-9a-f-]{36}$/i', $regionCode)) {
            return $regionCode;
        }

        return null;
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

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'station-' . time();
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = trim($value, '-');

        return $value !== '' ? $value : 'station-' . time();
    }

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
