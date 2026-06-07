<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\Rbac;
use RuntimeException;

final class AccessController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly UserRepository $userRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function users(): void
    {
        $this->guard('users:manage');
        // Faz H2-2: unified zarf — frontend artık tek noktadan unwrap eder.
        $this->respond(['code' => 0, 'result' => $this->userRepository->listUsers(), 'message' => 'Success']);
    }

    public function updateRoles(string $userId): void
    {
        $this->guard('users:manage');
        $payload = $this->readJsonPayload();
        $roles = $payload['roles'] ?? [];

        if (is_string($roles)) {
            $decoded = json_decode($roles, true);
            $roles = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($roles)) {
            throw new RuntimeException('Roles payload is invalid.');
        }

        // Only persist known, assignable roles — reject typos/unknown roles.
        $roles = Rbac::sanitizeRoles($roles);
        if ($roles === []) {
            throw new RuntimeException('At least one valid role is required.');
        }

        $user = $this->userRepository->updateRoles($userId, $roles);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $this->auditLogRepository->log('admin', 'update_roles', 'user', $userId, ['roles' => $user['roles'] ?? []]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 0,
            'result' => $user,
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function toggleActive(string $userId): void
    {
        $this->guard('users:manage');
        $payload = $this->readJsonPayload();
        $isActive = $this->readBool($payload['is_active'] ?? true);

        $user = $this->userRepository->toggleActive($userId, $isActive);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        $this->auditLogRepository->log('admin', 'toggle_user_active', 'user', $userId, ['is_active' => $isActive]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 0,
            'result' => $user,
            'message' => 'Success',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Admin sets a new password for a user (super only). */
    public function resetPassword(string $userId): void
    {
        $this->guard('users:manage');
        $payload = $this->readJsonPayload();
        $next = (string) ($payload['new_password'] ?? '');
        if (strlen($next) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }
        // Faz H3-5: konfig edilebilir bcrypt cost (env BCRYPT_COST).
        $this->userRepository->updatePassword($userId, \RadioSaaS\Service\PasswordHasher::hash($next));
        $this->auditLogRepository->log('admin', 'reset_password', 'user', $userId, []);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'result' => ['reset' => true], 'message' => 'Success'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Admin clears a user's 2FA so they can re-enrol (super only). */
    public function resetMfa(string $userId): void
    {
        $this->guard('users:manage');
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }
        $this->userRepository->disableMfa($userId);
        $this->auditLogRepository->log('admin', 'reset_mfa', 'user', $userId, []);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'result' => ['mfa_reset' => true], 'message' => 'Success'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function auditLogs(): void
    {
        $this->guard('audit:view');
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
        $filters = [
            'actor_username' => trim((string) ($_GET['actor_username'] ?? '')),
            'action' => trim((string) ($_GET['action'] ?? '')),
            'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
            'entity_id' => trim((string) ($_GET['entity_id'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];

        if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
            $csv = $this->auditLogRepository->exportCsv($filters, $limit);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit-logs.csv"');
            echo $csv;
            return;
        }

        // Faz H2-2: unified zarf. Listenin tipi `logs` adı altında çıkar
        // ki NOC + dashboard + access aynı normalizeList('logs') ile okusun.
        $this->respond([
            'code' => 0,
            'result' => ['logs' => $this->auditLogRepository->listLogs($filters, $limit)],
            'message' => 'Success',
        ]);
    }

    /** Faz H2-2: ortak JSON respond helper. */
    private function respond(array $data): void
    {
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
}
