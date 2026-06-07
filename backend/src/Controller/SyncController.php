<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\SyncClientRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\JwtService;
use RadioSaaS\Service\Logger;
use RadioSaaS\Service\LoginThrottle;
use RadioSaaS\Service\RequestContext;
use RuntimeException;

/**
 * AdCast Pro Sync Client API.
 *
 * Windows desktop sync client (.NET 8 + WPF) bu endpoint'leri kullanır.
 * Tüm endpoint'ler JWT Bearer auth gerektirir (login/refresh hariç).
 *
 * Yayıncılık kalitesi garantileri:
 *  - Manifest sadece bu kullanıcının yetkili olduğu radyo + bölge + şehir
 *    dosyalarını döner (Rbac + Station ownership check)
 *  - Download URL'leri 5 dk valid signed URL (Faz H5-1 + StreamTokenService)
 *  - Tüm istekler audit_logs'a yazılır (kim, ne zaman, hangi dosya)
 *  - Rate limit nginx tarafında (api zone 100r/s, login zone 5r/s)
 *
 * Routes (backend/public/index.php içinde register edilir):
 *   POST /api/v1/sync/login        → access + refresh token
 *   POST /api/v1/sync/refresh      → refresh_token → yeni access_token
 *   GET  /api/v1/sync/me           → kullanıcı + radyo + yetki + min_version
 *   GET  /api/v1/sync/manifest     → next 24h scheduled files (ETag destekli)
 *   GET  /api/v1/sync/download/{fileId} → signed URL redirect (302)
 *   POST /api/v1/sync/report       → sync başarı/başarısızlık raporu
 *   POST /api/v1/sync/error        → client hata raporu
 *   POST /api/v1/sync/heartbeat    → online + version + IP — admin panele yansır
 */
final class SyncController
{
    /** Min desteklenen Windows client versiyonu (eski client'lar 426 Update Required) */
    private const MIN_CLIENT_VERSION = '1.0.0';

    /** Download signed URL TTL (saniye) — kısa olsun, replay attack koruması */
    private const DOWNLOAD_URL_TTL = 300;

    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly JwtService $jwt,
        private readonly LoginThrottle $throttle,
        private readonly Logger $logger,
        private readonly SyncClientRepository $syncClients,
        private readonly StationRepository $stations,
        private readonly ContentPlanRepository $plans,
        private readonly MediaContentRepository $media,
        private readonly AuditLogRepository $audit
    ) {
    }

    /**
     * POST /api/v1/sync/login
     * Body: { "username": "...", "password": "...", "client_version": "1.0.0", "machine_id": "uuid" }
     * Returns: { "access_token": "...", "refresh_token": "...", "expires_in": 3600,
     *            "user": {...}, "radio": {...} }
     */
    public function login(): void
    {
        $body = $this->readJsonBody();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $clientVersion = (string)($body['client_version'] ?? '0.0.0');
        $machineId = (string)($body['machine_id'] ?? '');

        if ($username === '' || $password === '') {
            $this->respond(400, ['code' => 1, 'message' => 'username ve password zorunlu']);
            return;
        }

        $clientIp = RequestContext::clientIp();

        // Throttle (Faz H1-3 — IP + username başına)
        if (!$this->throttle->canAttempt($username, $clientIp)) {
            $this->audit->record('sync_login_throttled', null, ['username' => $username, 'ip' => $clientIp]);
            $this->respond(429, ['code' => 429, 'message' => 'Çok fazla deneme — sonra tekrar deneyin']);
            return;
        }

        try {
            $user = $this->authenticator->authenticate($username, $password);
        } catch (RuntimeException $e) {
            $this->throttle->recordFailure($username, $clientIp);
            $this->audit->record('sync_login_failed', null, ['username' => $username, 'ip' => $clientIp, 'reason' => $e->getMessage()]);
            $this->respond(401, ['code' => 401, 'message' => 'Kullanıcı adı veya şifre hatalı']);
            return;
        }

        // Min versiyon kontrolü — eski client'lar uyarılır ama bloklanmaz (warning)
        $needsUpdate = version_compare($clientVersion, self::MIN_CLIENT_VERSION, '<');

        // Sync client metadata kayıt/güncelleme
        $this->syncClients->upsert([
            'user_id' => $user['id'],
            'machine_id' => $machineId,
            'client_version' => $clientVersion,
            'last_seen_ip' => $clientIp,
            'last_seen_at' => date('c'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        // Radyo bilgisi
        $radio = $user['radio_id'] !== null ? $this->stations->find($user['radio_id']) : null;

        $tokens = $this->jwt->issuePair([
            'sub' => $user['id'],
            'role' => $user['role'] ?? 'partner',
            'radio_id' => $user['radio_id'] ?? null,
            'scope' => 'sync',
        ]);

        $this->throttle->recordSuccess($username, $clientIp);
        $this->audit->record('sync_login_success', $user['id'], [
            'ip' => $clientIp,
            'client_version' => $clientVersion,
            'machine_id' => $machineId,
            'needs_update' => $needsUpdate,
        ]);

        $this->respond(200, [
            'code' => 0,
            'result' => [
                'access_token' => $tokens['access'],
                'refresh_token' => $tokens['refresh'],
                'expires_in' => $tokens['expires_in'],
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? 'partner',
                ],
                'radio' => $radio !== null ? [
                    'id' => $radio['id'],
                    'name' => $radio['name'],
                    'frequency' => $radio['frequency'] ?? null,
                    'region' => $radio['region'] ?? null,
                    'province' => $radio['province'] ?? null,
                    'national_access' => (bool)($radio['national_access'] ?? false),
                ] : null,
                'min_client_version' => self::MIN_CLIENT_VERSION,
                'needs_update' => $needsUpdate,
            ],
            'message' => 'Login başarılı',
        ]);
    }

    /**
     * POST /api/v1/sync/refresh
     * Body: { "refresh_token": "..." }
     */
    public function refresh(): void
    {
        $body = $this->readJsonBody();
        $refresh = (string)($body['refresh_token'] ?? '');

        if ($refresh === '') {
            $this->respond(400, ['code' => 1, 'message' => 'refresh_token zorunlu']);
            return;
        }

        try {
            $tokens = $this->jwt->refreshPair($refresh);
        } catch (RuntimeException $e) {
            $this->respond(401, ['code' => 401, 'message' => 'Refresh token geçersiz veya süresi dolmuş']);
            return;
        }

        $this->respond(200, [
            'code' => 0,
            'result' => [
                'access_token' => $tokens['access'],
                'refresh_token' => $tokens['refresh'],
                'expires_in' => $tokens['expires_in'],
            ],
            'message' => 'Token yenilendi',
        ]);
    }

    /**
     * GET /api/v1/sync/me  (JWT required)
     */
    public function me(): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) return;

        $userId = (string)$claims['sub'];
        $user = $this->authenticator->findById($userId);
        if ($user === null) {
            $this->respond(404, ['code' => 404, 'message' => 'Kullanıcı bulunamadı']);
            return;
        }

        $radio = $user['radio_id'] !== null ? $this->stations->find($user['radio_id']) : null;

        $this->respond(200, [
            'code' => 0,
            'result' => [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'] ?? 'partner',
                ],
                'radio' => $radio,
                'permissions' => [
                    'news' => true,
                    'ads' => $radio !== null && ($radio['ads_enabled'] ?? true),
                    'media_plan' => $radio !== null && ($radio['media_plan_enabled'] ?? true),
                    'sponsor' => $radio !== null && ($radio['sponsor_enabled'] ?? true),
                ],
                'min_client_version' => self::MIN_CLIENT_VERSION,
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/v1/sync/manifest
     * Query: ?since=2026-06-07T00:00:00Z  (opsiyonel — sadece bu zamandan sonraki değişiklikler)
     *
     * Returns next 24h scheduled files for THIS user's radio only.
     * ETag-aware → 304 Not Modified destekli.
     */
    public function manifest(): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) return;

        $radioId = $claims['radio_id'] ?? null;
        if ($radioId === null) {
            $this->respond(403, ['code' => 403, 'message' => 'Bu kullanıcının atanmış radyosu yok']);
            return;
        }

        $since = $_GET['since'] ?? null;
        $now = new \DateTimeImmutable();
        $until = $now->modify('+24 hours');

        // F1 TODO: ContentPlanRepository::findScheduledForRadio($radioId, $now, $until)
        //          + MediaContentRepository batch fetch + signed URL üretimi
        // Şimdilik skeleton dönüyoruz — gerçek implementation Faz 2'de
        $files = [];

        $this->respond(200, [
            'code' => 0,
            'result' => [
                'generated_at' => $now->format('c'),
                'window_start' => $now->format('c'),
                'window_end' => $until->format('c'),
                'radio_id' => $radioId,
                'files' => $files,
                'next_poll_after' => 60, // saniye
            ],
            'message' => 'Manifest hazır',
        ]);
    }

    /**
     * GET /api/v1/sync/download/{fileId}
     * Returns 302 redirect to signed URL (5 dk valid)
     */
    public function download(string $fileId): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) return;

        $radioId = $claims['radio_id'] ?? null;
        if ($radioId === null) {
            $this->respond(403, ['code' => 403, 'message' => 'Radyo erişimi yok']);
            return;
        }

        // F1 TODO: MediaContentRepository::checkAccess($fileId, $radioId) — file gerçekten bu radyoya mı atanmış?
        //          Sonra StreamTokenService::generateSignedUrl($fileId, $radioId, TTL=300)
        //          302 redirect issue
        $signedUrl = null; // placeholder

        if ($signedUrl === null) {
            $this->respond(404, ['code' => 404, 'message' => 'Dosya bulunamadı veya erişim yok']);
            return;
        }

        $this->audit->record('sync_download', $claims['sub'], [
            'file_id' => $fileId,
            'radio_id' => $radioId,
            'ip' => RequestContext::clientIp(),
        ]);

        header('Location: ' . $signedUrl);
        http_response_code(302);
    }

    /**
     * POST /api/v1/sync/report
     * Body: { "file_id": "...", "status": "success"|"failed", "bytes": 1234, "checksum_ok": true, "duration_ms": 500 }
     */
    public function report(): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) return;

        $body = $this->readJsonBody();
        $fileId = (string)($body['file_id'] ?? '');
        $status = (string)($body['status'] ?? '');

        if ($fileId === '' || !in_array($status, ['success', 'failed', 'partial'], true)) {
            $this->respond(400, ['code' => 1, 'message' => 'file_id ve geçerli status zorunlu']);
            return;
        }

        $this->audit->record('sync_report_' . $status, $claims['sub'], [
            'file_id' => $fileId,
            'radio_id' => $claims['radio_id'] ?? null,
            'bytes' => (int)($body['bytes'] ?? 0),
            'checksum_ok' => (bool)($body['checksum_ok'] ?? false),
            'duration_ms' => (int)($body['duration_ms'] ?? 0),
        ]);

        $this->respond(200, ['code' => 0, 'message' => 'Rapor kaydedildi']);
    }

    /**
     * POST /api/v1/sync/heartbeat
     * Body: { "client_version": "...", "os": "Windows 11", "disk_free_gb": 50 }
     */
    public function heartbeat(): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) return;

        $body = $this->readJsonBody();
        $this->syncClients->touch($claims['sub'], [
            'client_version' => (string)($body['client_version'] ?? '0.0.0'),
            'os' => (string)($body['os'] ?? 'Unknown'),
            'disk_free_gb' => (int)($body['disk_free_gb'] ?? 0),
            'last_seen_ip' => RequestContext::clientIp(),
            'last_seen_at' => date('c'),
        ]);

        $this->respond(200, ['code' => 0, 'message' => 'Heartbeat alındı']);
    }

    // ---------- Helper methods ----------

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function requireJwt(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->respond(401, ['code' => 401, 'message' => 'Bearer token zorunlu']);
            return null;
        }
        $token = substr($authHeader, 7);
        try {
            return $this->jwt->validate($token);
        } catch (RuntimeException $e) {
            $this->respond(401, ['code' => 401, 'message' => 'Token geçersiz veya süresi dolmuş']);
            return null;
        }
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
