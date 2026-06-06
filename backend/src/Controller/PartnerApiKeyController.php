<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Exception\ForbiddenException;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\PartnerApiKeyRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\ApiKeyService;
use RuntimeException;

/**
 * Partner API key management — admin can issue/revoke for any station,
 * station_user can manage own keys via the /portal/api-keys aliases.
 */
final class PartnerApiKeyController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly PartnerApiKeyRepository $repo,
        private readonly ApiKeyService $service,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    // -- admin -----------------------------------------------------------

    public function adminList(string $stationId): void
    {
        $this->authenticator->authorize($this->token(), 'partner:provision');
        $this->respond([
            'code' => 0,
            'result' => ['keys' => $this->repo->listForStation($stationId)],
        ]);
    }

    public function adminIssue(string $stationId): void
    {
        $user = $this->authenticator->authorize($this->token(), 'partner:provision');
        $payload = $this->readJsonPayload();
        $name = (string) ($payload['name'] ?? '');
        $scopes = $this->normaliseScopes($payload['scopes'] ?? []);
        try {
            $res = $this->service->issue($stationId, $name, $scopes, (string) ($user['id'] ?? '') ?: null);
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'admin'),
            'api_key_issue',
            'station',
            $stationId,
            ['key_id' => (string) ($res['record']['id'] ?? ''), 'name' => $name]
        );
        $this->respond([
            'code' => 0,
            'result' => [
                'record' => $res['record'],
                // ONE-SHOT — admin must hand this to the partner now.
                'one_time_key' => $res['key'],
            ],
        ], 201);
    }

    public function adminRevoke(string $stationId, string $keyId): void
    {
        $user = $this->authenticator->authorize($this->token(), 'partner:provision');
        $ok = $this->repo->revoke($keyId, $stationId);
        if (!$ok) {
            $this->respond(['code' => 1, 'message' => 'Anahtar bulunamadı.'], 404);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'admin'),
            'api_key_revoke',
            'station',
            $stationId,
            ['key_id' => $keyId]
        );
        $this->respond(['code' => 0, 'result' => ['revoked' => true]]);
    }

    // -- partner (own tenant) -------------------------------------------

    public function portalList(): void
    {
        $user = $this->portalUser();
        $this->respond([
            'code' => 0,
            'result' => ['keys' => $this->repo->listForStation((string) $user['station_id'])],
        ]);
    }

    public function portalIssue(): void
    {
        $user = $this->portalUser();
        $payload = $this->readJsonPayload();
        $name = (string) ($payload['name'] ?? '');
        $scopes = $this->normaliseScopes($payload['scopes'] ?? []);
        try {
            $res = $this->service->issue(
                (string) $user['station_id'],
                $name,
                $scopes,
                (string) ($user['id'] ?? '') ?: null
            );
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'partner'),
            'api_key_issue',
            'station',
            (string) $user['station_id'],
            ['key_id' => (string) ($res['record']['id'] ?? ''), 'name' => $name]
        );
        $this->respond([
            'code' => 0,
            'result' => [
                'record' => $res['record'],
                'one_time_key' => $res['key'],
            ],
        ], 201);
    }

    public function portalRevoke(string $keyId): void
    {
        $user = $this->portalUser();
        $ok = $this->repo->revoke($keyId, (string) $user['station_id']);
        if (!$ok) {
            $this->respond(['code' => 1, 'message' => 'Anahtar bulunamadı.'], 404);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'partner'),
            'api_key_revoke',
            'station',
            (string) $user['station_id'],
            ['key_id' => $keyId]
        );
        $this->respond(['code' => 0, 'result' => ['revoked' => true]]);
    }

    private function portalUser(): array
    {
        $user = $this->authenticator->authorize($this->token(), 'portal:view');
        $roles = (array) ($user['roles'] ?? []);
        if (!in_array('station_user', $roles, true) || empty($user['station_id'])) {
            throw new ForbiddenException('Bu endpoint yalnızca partner radyo kullanıcısı içindir.');
        }
        return $user;
    }

    /** @return list<string> */
    private function normaliseScopes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $raw),
            static fn (string $v): bool => $v !== ''
        ));
    }

    private function token(): ?string
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if (is_string($token) && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            return trim($matches[1]);
        }
        return is_string($token) ? $token : null;
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

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
