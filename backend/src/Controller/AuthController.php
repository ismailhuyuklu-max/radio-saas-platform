<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\AdminSessionRepository;
use RadioSaaS\Repository\LoginThrottleRepository;
use RadioSaaS\Service\LoginThrottle;
use RadioSaaS\Service\TotpService;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AdminSessionRepository $sessionRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ?LoginThrottleRepository $throttleRepository = null
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

        // Brute-force throttle: lock by username AND by client IP. The IP lock
        // blunts password-spraying across many usernames from one source.
        $ipKey = 'ip:' . $this->clientIp();
        if ($this->throttleRepository !== null) {
            foreach ([$username, $ipKey] as $key) {
                $status = $this->throttleRepository->status($key);
                if (LoginThrottle::isLocked($status['locked_until'], time())) {
                    $retry = LoginThrottle::retryAfter($status['locked_until'], time());
                    header('Retry-After: ' . $retry);
                    $this->respond([
                        'code' => 1,
                        'result' => null,
                        'message' => 'Çok fazla başarısız deneme. ' . ceil($retry / 60) . ' dakika sonra tekrar deneyin.',
                    ], 429);
                }
            }
        }

        $user = $this->userRepository->findByUsername($username);
        if ($user === null || !($user['is_active'] ?? false) || !password_verify($password, (string) $user['password_hash'])) {
            $this->throttleRepository?->registerFailure($username);
            $this->throttleRepository?->registerFailure($ipKey);
            $this->respond([
                'code' => 1,
                'result' => null,
                'message' => 'Invalid username or password.',
            ], 401);
        }

        // Password correct — clear both throttle counters.
        $this->throttleRepository?->reset($username);
        $this->throttleRepository?->reset($ipKey);

        // Two-factor: if enabled, defer the session until a valid TOTP code is
        // supplied. Return a short-lived signed challenge instead of a session.
        if (filter_var($user['mfa_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $this->auditLogRepository->log((string) $user['username'], 'mfa_challenge', 'user', (string) $user['id'], []);
            $this->respond([
                'code' => 0,
                'result' => [
                    'mfa_required' => true,
                    'mfa_token' => $this->signChallenge((string) $user['id']),
                    'username' => (string) $user['username'],
                ],
                'message' => 'MFA required',
            ]);
        }

        $this->markLoginTimestamp((string) $user['id']);
        $this->auditLogRepository->log((string) $user['username'], 'login', 'user', (string) $user['id'], [
            'roles' => $user['roles'] ?? [],
        ]);

        $token = $this->sessionRepository->create((string) $user['id']);
        $this->issueSessionCookie($token);
        $this->issueCsrfCookie();

        $this->respond([
            'code' => 0,
            'result' => $this->toAuthResult($user, $token),
            'message' => 'Success',
        ]);
    }

    /** Second login step: exchange a valid TOTP (or recovery) code for a session. */
    public function mfaVerify(): void
    {
        $payload = $this->readJsonPayload();
        $mfaToken = (string) ($payload['mfa_token'] ?? '');
        $code = trim((string) ($payload['code'] ?? ''));

        $userId = $this->verifyChallenge($mfaToken);
        if ($userId === null) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'MFA challenge invalid or expired.'], 401);
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null || !filter_var($user['mfa_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'MFA is not active for this account.'], 401);
        }

        $secret = (string) ($user['mfa_secret'] ?? '');
        $usedRecovery = false;
        $ok = $secret !== '' && TotpService::verify($secret, $code, time());
        if (!$ok && $code !== '') {
            $usedRecovery = $this->userRepository->consumeRecoveryCode($userId, TotpService::hashRecoveryCode($code));
            $ok = $usedRecovery;
        }

        if (!$ok) {
            $this->auditLogRepository->log((string) $user['username'], 'mfa_failed', 'user', $userId, []);
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Invalid authentication code.'], 401);
        }

        $this->markLoginTimestamp($userId);
        $this->auditLogRepository->log((string) $user['username'], 'login', 'user', $userId, [
            'mfa' => true,
            'recovery' => $usedRecovery,
        ]);

        $token = $this->sessionRepository->create($userId);
        $this->issueSessionCookie($token);
        $this->issueCsrfCookie();

        $this->respond([
            'code' => 0,
            'result' => $this->toAuthResult($user, $token),
            'message' => 'Success',
        ]);
    }

    /** Start MFA enrolment: generate + store a secret, return it for QR/manual entry. */
    public function mfaSetup(): void
    {
        $user = $this->requireUser();
        $secret = TotpService::generateSecret();
        $this->userRepository->setMfaSecret((string) $user['id'], $secret);

        $this->respond([
            'code' => 0,
            'result' => [
                'secret' => $secret,
                'otpauth_uri' => TotpService::provisioningUri($secret, (string) $user['username']),
            ],
            'message' => 'Success',
        ]);
    }

    /** Confirm enrolment with a code; activates MFA and returns recovery codes once. */
    public function mfaEnable(): void
    {
        $user = $this->requireUser();
        $payload = $this->readJsonPayload();
        $code = trim((string) ($payload['code'] ?? ''));
        $secret = (string) ($user['mfa_secret'] ?? '');

        if ($secret === '') {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Run MFA setup first.'], 400);
        }
        if (!TotpService::verify($secret, $code, time())) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Invalid authentication code.'], 400);
        }

        $codes = TotpService::generateRecoveryCodes(8);
        $hashes = array_map([TotpService::class, 'hashRecoveryCode'], $codes);
        $this->userRepository->enableMfa((string) $user['id'], $hashes);
        $this->auditLogRepository->log((string) $user['username'], 'mfa_enabled', 'user', (string) $user['id'], []);

        $this->respond([
            'code' => 0,
            'result' => ['enabled' => true, 'recovery_codes' => $codes],
            'message' => 'Success',
        ]);
    }

    /** Disable MFA after re-verifying a code (or recovery code). */
    public function mfaDisable(): void
    {
        $user = $this->requireUser();
        $payload = $this->readJsonPayload();
        $code = trim((string) ($payload['code'] ?? ''));
        $secret = (string) ($user['mfa_secret'] ?? '');

        if (filter_var($user['mfa_enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $ok = $secret !== '' && TotpService::verify($secret, $code, time());
            if (!$ok && $code !== '') {
                $ok = $this->userRepository->consumeRecoveryCode((string) $user['id'], TotpService::hashRecoveryCode($code));
            }
            if (!$ok) {
                $this->respond(['code' => 1, 'result' => null, 'message' => 'Invalid authentication code.'], 400);
            }
        }

        $this->userRepository->disableMfa((string) $user['id']);
        $this->auditLogRepository->log((string) $user['username'], 'mfa_disabled', 'user', (string) $user['id'], []);

        $this->respond(['code' => 0, 'result' => ['enabled' => false], 'message' => 'Success']);
    }

    public function mfaStatus(): void
    {
        $user = $this->requireUser();
        $this->respond([
            'code' => 0,
            'result' => [
                'enabled' => filter_var($user['mfa_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'pending' => !filter_var($user['mfa_enabled'] ?? false, FILTER_VALIDATE_BOOL)
                    && (string) ($user['mfa_secret'] ?? '') !== '',
            ],
            'message' => 'Success',
        ]);
    }

    /** Self-service password change (verifies the current password). */
    public function changePassword(): void
    {
        $user = $this->requireUser();
        $payload = $this->readJsonPayload();
        $current = (string) ($payload['current_password'] ?? '');
        $next = (string) ($payload['new_password'] ?? '');

        if (strlen($next) < 6) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Yeni şifre en az 6 karakter olmalı.'], 400);
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Mevcut şifre hatalı.'], 400);
        }

        $this->userRepository->updatePassword((string) $user['id'], password_hash($next, PASSWORD_BCRYPT));
        // Invalidate every other session after a password change.
        $token = (string) $this->extractToken();
        $this->sessionRepository->revokeAllForUserExcept((string) $user['id'], $token);
        $this->auditLogRepository->log((string) $user['username'], 'change_password', 'user', (string) $user['id'], []);

        $this->respond(['code' => 0, 'result' => ['changed' => true], 'message' => 'Success']);
    }

    /** List the current user's active sessions. */
    public function sessions(): void
    {
        $user = $this->requireUser();
        $token = (string) $this->extractToken();
        $this->respond([
            'code' => 0,
            'result' => $this->sessionRepository->listActiveForUser((string) $user['id'], $token),
            'message' => 'Success',
        ]);
    }

    /** Revoke every other session ("log out everywhere else"). */
    public function revokeOtherSessions(): void
    {
        $user = $this->requireUser();
        $token = (string) $this->extractToken();
        $count = $this->sessionRepository->revokeAllForUserExcept((string) $user['id'], $token);
        $this->auditLogRepository->log((string) $user['username'], 'revoke_sessions', 'user', (string) $user['id'], ['count' => $count]);
        $this->respond(['code' => 0, 'result' => ['revoked' => $count], 'message' => 'Success']);
    }

    public function logout(): void
    {
        $token = $this->extractToken();
        $user = null;
        if ($token !== null && $token !== '') {
            $user = $this->sessionRepository->findActiveUserByToken($token);
            $this->sessionRepository->revokeByToken($token);
        }
        // Faz 21: master prompt's aktivite kayıtları list demands a logout
        // audit. We still emit it even when the token was already invalid so
        // a brute-force logout call is visible.
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'unknown'),
            'logout',
            'user',
            isset($user['id']) ? (string) $user['id'] : null,
            []
        );
        $this->clearSessionCookie();
        $this->clearCsrfCookie();

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

    /** Resolve the current session user or respond 401. */
    private function requireUser(): array
    {
        $token = $this->extractToken();
        $user = ($token !== null && $token !== '')
            ? $this->sessionRepository->findActiveUserByToken($token)
            : null;
        if ($user === null) {
            $this->respond(['code' => 1, 'result' => null, 'message' => 'Unauthorized'], 401);
        }
        return $user;
    }

    private function clientIp(): string
    {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            // First hop is the original client when set by a trusted proxy (nginx).
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                return substr($first, 0, 45);
            }
        }
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }

    private function challengeSecret(): string
    {
        $key = (string) (getenv('APP_KEY') ?: '');
        $isProd = (getenv('APP_ENV') ?: 'local') === 'production';

        // Fail closed in production: the MFA challenge HMAC must use a strong,
        // dedicated key — never the DB password or a hard-coded fallback.
        if ($isProd && strlen($key) < 32) {
            throw new \RuntimeException(
                'APP_KEY (en az 32 karakter) production ortaminda MFA challenge imzasi icin zorunludur.'
            );
        }

        return $key !== '' ? $key : 'local-dev-only-mfa-challenge-secret-change-me';
    }

    private function signChallenge(string $userId, int $ttlSeconds = 300): string
    {
        $expires = time() + $ttlSeconds;
        $payload = $userId . '|' . $expires;
        $sig = hash_hmac('sha256', $payload, $this->challengeSecret());
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    private function verifyChallenge(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = explode('|', $raw);
        if (count($parts) !== 3) {
            return null;
        }
        [$userId, $expires, $sig] = $parts;
        if ((int) $expires < time()) {
            return null;
        }
        $expected = hash_hmac('sha256', $userId . '|' . $expires, $this->challengeSecret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return $userId;
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

    /** Non-HttpOnly CSRF token for the double-submit pattern (JS must read it). */
    private function issueCsrfCookie(int $ttlSeconds = 28800): void
    {
        $this->writeCsrfCookie(bin2hex(random_bytes(16)), time() + $ttlSeconds);
    }

    private function clearCsrfCookie(): void
    {
        $this->writeCsrfCookie('', time() - 3600);
    }

    private function writeCsrfCookie(string $value, int $expires): void
    {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'http');
        $secure = $proto === 'https' || (getenv('APP_ENV') ?: 'local') === 'production';

        setcookie('radio_csrf', $value, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => false, // readable by JS to echo back as X-CSRF-Token
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
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
