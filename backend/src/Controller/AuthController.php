<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\AdminSessionRepository;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AdminSessionRepository $sessionRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function login(): void
    {
        $payload = $this->readJsonPayload();
        $username = trim((string) ($payload['username'] ?? $payload['account'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->respond([
                'code' => 1,
                'result' => null,
                'message' => 'Username and password are required.',
            ], 400);
        }

        $user = $this->userRepository->findByUsername($username);
        if ($user === null || !($user['is_active'] ?? false) || !password_verify($password, (string) $user['password_hash'])) {
            $this->respond([
                'code' => 1,
                'result' => null,
                'message' => 'Invalid username or password.',
            ], 401);
        }

        $this->markLoginTimestamp((string) $user['id']);
        $this->auditLogRepository->log((string) $user['username'], 'login', 'user', (string) $user['id'], [
            'roles' => $user['roles'] ?? [],
        ]);

        $token = $this->sessionRepository->create((string) $user['id']);
        $this->issueSessionCookie($token);

        $this->respond([
            'code' => 0,
            'result' => $this->toAuthResult($user, $token),
            'message' => 'Success',
        ]);
    }

    public function logout(): void
    {
        $token = $this->extractToken();
        if ($token !== null && $token !== '') {
            $this->sessionRepository->revokeByToken($token);
        }
        $this->clearSessionCookie();

        $this->respond([
            'code' => 0,
            'result' => null,
            'message' => 'Success',
        ]);
    }

    public function userInfo(): void
    {
        $token = $this->extractToken();
        if ($token === null || $token === '') {
            $this->respond([
                'code' => 1,
                'result' => null,
                'message' => 'Unauthorized',
            ], 401);
        }

        $user = $this->sessionRepository->findActiveUserByToken($token);
        if ($user === null) {
            $this->respond([
                'code' => 1,
                'result' => null,
                'message' => 'Unauthorized',
            ], 401);
        }

        $this->respond([
            'code' => 0,
            'result' => $this->toAuthResult($user, $token),
            'message' => 'Success',
        ]);
    }

    private function toAuthResult(array $user, string $token): array
    {
        $roles = array_values(array_filter(
            (array) ($user['roles'] ?? []),
            static fn (mixed $role): bool => is_string($role) && $role !== ''
        ));

        if ($roles === []) {
            $roles = ['super'];
        }

        // NOTE: the raw session token is intentionally NOT returned in the body.
        // It lives only in the HttpOnly cookie, so it cannot leak via XSS/logging.
        return [
            'userId' => (string) $user['id'],
            'username' => (string) $user['username'],
            'realName' => (string) $user['real_name'],
            'roles' => $roles,
        ];
    }

    private function markLoginTimestamp(string $userId): void
    {
        $this->userRepository->touchLastLogin($userId);
    }

    private function readJsonPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $_POST;
    }

    private function extractToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return $_SERVER['HTTP_X_API_TOKEN'] ?? ($_COOKIE['radio_session'] ?? null);
    }

    private function issueSessionCookie(string $token, int $ttlSeconds = 28800): void
    {
        $this->writeSessionCookie($token, time() + $ttlSeconds);
    }

    private function clearSessionCookie(): void
    {
        $this->writeSessionCookie('', time() - 3600);
    }

    private function writeSessionCookie(string $value, int $expires): void
    {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http');
        $secure = $proto === 'https' || (getenv('APP_ENV') ?: 'local') === 'production';

        setcookie('radio_session', $value, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
    }

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
