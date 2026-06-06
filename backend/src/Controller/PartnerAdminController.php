<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\RadioCredentialService;
use RuntimeException;

/**
 * Admin-side partner-radio operations: provision a station user, rotate its
 * password, edit the corporate profile card. The one-shot plaintext password
 * is returned ONLY in the immediate response — never logged, never persisted.
 */
final class PartnerAdminController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly StationRepository $stationRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly RadioCredentialService $credentialService
    ) {
    }

    public function provision(string $stationId): void
    {
        $this->guard('partner:provision');
        try {
            $res = $this->credentialService->provision($stationId);
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log('admin', 'partner_provision', 'station', $stationId, [
            'username' => $res['username'],
            'user_id' => (string) ($res['user']['id'] ?? ''),
        ]);
        $this->respond([
            'code' => 0,
            'result' => [
                'username' => $res['username'],
                // SHOWN ONCE — frontend must surface this to the admin and
                // then discard. No follow-up endpoint can read it back.
                'one_time_password' => $res['password'],
                'user_id' => (string) ($res['user']['id'] ?? ''),
            ],
            'message' => 'Provisioned',
        ], 201);
    }

    public function rotatePassword(string $stationId): void
    {
        $this->guard('partner:provision');
        try {
            $res = $this->credentialService->rotatePassword($stationId);
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log('admin', 'partner_password_rotate', 'station', $stationId, [
            'user_id' => $res['user_id'],
        ]);
        $this->respond([
            'code' => 0,
            'result' => ['one_time_password' => $res['password']],
            'message' => 'Rotated',
        ]);
    }

    public function updateProfile(string $stationId): void
    {
        $this->guard('partner:provision');
        $payload = $this->readJsonPayload();
        $station = $this->stationRepository->updateProfile($stationId, $payload);
        if ($station === null) {
            $this->respond(['code' => 1, 'message' => 'Radyo bulunamadı.'], 404);
            return;
        }
        $this->auditLogRepository->log('admin', 'partner_profile_update', 'station', $stationId, [
            'fields' => array_keys($payload),
        ]);
        $this->respond(['code' => 0, 'result' => $station, 'message' => 'Updated']);
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

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
