<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\PublisherSupportRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\UserRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\JwtService;
use RuntimeException;

/**
 * Destek Modulu — yayinci destek talepleri.
 *
 *  - Sync client (yayinci, JWT): POST /api/v1/sync/support
 *      Radyo bilgileri (ad/frekans/bolge/il) ISTEMCIDEN ALINMAZ; JWT'deki
 *      kullaniciya bagli station kaydindan dogrulanir.
 *  - Admin (support:manage): GET /api/v1/support/requests, GET .../{id},
 *      PATCH .../{id} (durum + admin notu).
 *
 * Rate limit: ayni istasyon 5 dakikada en fazla 3 talep.
 */
final class PublisherSupportController
{
    private const RATE_WINDOW_MINUTES = 5;
    private const RATE_MAX = 3;
    private const MESSAGE_MIN = 10;

    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt,
        private readonly StationRepository $stations,
        private readonly PublisherSupportRepository $tickets,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AdminAuthenticator $authenticator
    ) {
    }

    // ============================ Yayinci (sync client) ============================

    public function createFromSync(): void
    {
        $claims = $this->requireJwt();
        if ($claims === null) {
            return;
        }

        $userId = (string) ($claims['sub'] ?? '');
        $user = $userId !== '' ? $this->users->findById($userId) : null;
        if ($user === null) {
            $this->respond(401, ['code' => 401, 'message' => 'Oturum gecersiz.']);
            return;
        }

        // Radyo bilgileri SUNUCUDA dogrulanir — istemci gonderse de dikkate alinmaz.
        $stationId = (string) ($user['station_id'] ?? '');
        $station = $stationId !== '' ? $this->stations->findById($stationId) : null;
        if ($station === null) {
            $this->respond(403, [
                'code' => 1,
                'message' => 'Radyo bilgileri alinamadi. Lutfen yoneticinizle iletisime gecin.',
            ]);
            return;
        }

        // Rate limit
        if ($this->tickets->countRecentByStation($stationId, self::RATE_WINDOW_MINUTES) >= self::RATE_MAX) {
            $this->respond(429, [
                'code' => 1,
                'message' => 'Cok fazla destek talebi gonderdiniz. Lutfen birkac dakika sonra tekrar deneyin.',
            ]);
            return;
        }

        $payload = $this->readJsonPayload();
        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $message = $this->sanitize((string) ($payload['message'] ?? ''));

        $error = $this->validate($fullName, $phone, $email, $message);
        if ($error !== null) {
            $this->respond(400, ['code' => 1, 'message' => $error]);
            return;
        }

        try {
            $ticket = $this->tickets->create([
                'station_id' => $stationId,
                'radio_name' => $station['name'] ?? null,
                'frequency' => $station['frequency'] ?? null,
                'region' => $station['region_name'] ?? $station['region'] ?? null,
                'city' => $station['city_name'] ?? $station['province'] ?? null,
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'message' => $message,
            ]);
        } catch (RuntimeException $e) {
            $this->respond(400, ['code' => 1, 'message' => 'Destek talebi gonderilemedi.']);
            return;
        }

        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'publisher'),
            'support_ticket_create',
            'station',
            $stationId,
            ['ticket_id' => (string) ($ticket['id'] ?? '')]
        );

        $this->respond(201, [
            'code' => 0,
            'result' => ['id' => $ticket['id'] ?? null],
            'message' => 'Destek talebiniz basariyla iletildi.',
        ]);
    }

    // ================================ Admin ================================

    public function adminIndex(): void
    {
        $user = $this->guardAdmin();
        if ($user === null) {
            return;
        }

        $filters = [
            'status' => isset($_GET['status']) ? (string) $_GET['status'] : null,
            'keyword' => isset($_GET['keyword']) ? (string) $_GET['keyword'] : null,
        ];

        $this->respond(200, ['code' => 0, 'result' => $this->tickets->listTickets($filters)]);
    }

    public function adminShow(string $id): void
    {
        $user = $this->guardAdmin();
        if ($user === null) {
            return;
        }

        $ticket = $this->tickets->findById($id);
        if ($ticket === null) {
            $this->respond(404, ['code' => 1, 'message' => 'Talep bulunamadi.']);
            return;
        }

        $this->respond(200, ['code' => 0, 'result' => $ticket]);
    }

    public function adminUpdate(string $id): void
    {
        $user = $this->guardAdmin();
        if ($user === null) {
            return;
        }

        $existing = $this->tickets->findById($id);
        if ($existing === null) {
            $this->respond(404, ['code' => 1, 'message' => 'Talep bulunamadi.']);
            return;
        }

        $payload = $this->readJsonPayload();
        $status = array_key_exists('status', $payload) ? (string) $payload['status'] : null;
        $adminNote = array_key_exists('admin_note', $payload)
            ? $this->sanitize((string) $payload['admin_note'])
            : null;

        try {
            $ticket = $this->tickets->update($id, $status, $adminNote);
        } catch (RuntimeException $e) {
            $this->respond(400, ['code' => 1, 'message' => $e->getMessage()]);
            return;
        }

        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'admin'),
            'support_ticket_update',
            'station',
            (string) ($existing['station_id'] ?? ''),
            ['ticket_id' => $id, 'status' => $status]
        );

        $this->respond(200, ['code' => 0, 'result' => $ticket]);
    }

    // ================================ Helpers ================================

    private function validate(string $fullName, string $phone, string $email, string $message): ?string
    {
        if ($fullName === '') {
            return 'Ad Soyad zorunludur.';
        }
        if ($phone === '') {
            return 'Telefon zorunludur.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Gecerli bir e-posta adresi girin.';
        }
        if (mb_strlen($message) < self::MESSAGE_MIN) {
            return 'Destek mesaji en az ' . self::MESSAGE_MIN . ' karakter olmalidir.';
        }

        return null;
    }

    /** XSS — HTML etiketlerini temizle, kontrol karakterlerini kirp. */
    private function sanitize(string $value): string
    {
        $clean = strip_tags($value);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $clean) ?? $clean;

        return trim($clean);
    }

    private function guardAdmin(): ?array
    {
        try {
            return $this->authenticator->authorize($this->extractToken(), 'support:manage');
        } catch (\Throwable $e) {
            $this->respond(403, ['code' => 1, 'message' => 'Bu islem icin yetkiniz yok.']);
            return null;
        }
    }

    private function requireJwt(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->respond(401, ['code' => 401, 'message' => 'Bearer token zorunlu.']);
            return null;
        }

        try {
            return $this->jwt->verifyAccess(substr($authHeader, 7));
        } catch (RuntimeException $e) {
            $this->respond(401, ['code' => 401, 'message' => 'Token gecersiz veya suresi dolmus.']);
            return null;
        }
    }

    private function extractToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
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

    private function respond(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
