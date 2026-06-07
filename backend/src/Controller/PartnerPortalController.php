<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Exception\ForbiddenException;
use RadioSaaS\Exception\UnauthorizedException;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\ContentPlanRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\SponsorAdRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\StreamTokenService;

/**
 * Partner Radio Portal API — strictly tenant-scoped.
 *
 * Every endpoint resolves the caller to its station_id (from users.station_id)
 * and rejects any cross-tenant lookup. Station A can never read Station B,
 * not even by guessing UUIDs. Admin/manager roles bypass this wall so they
 * can preview a partner view in support contexts.
 */
final class PartnerPortalController
{
    // PURPOSE_TO_PART const kaldırıldı (Faz CTO-15) — kullanılmıyordu.
    // Portal media filtreleme MediaContentRepository tarafında part_code
    // join'i ile yapılır; explicit mapping gerektiğinde StreamTokenService
    // içindeki PURPOSE_LABELS kullanılır.

    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly StationRepository $stationRepository,
        private readonly ContentPlanRepository $planRepository,
        private readonly MediaContentRepository $mediaRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly StreamTokenService $tokenService,
        private readonly ?SponsorAdRepository $sponsorRepository = null
    ) {
    }

    /** GET /portal/me — corporate profile card + last login / broadcast. */
    public function me(): void
    {
        $ctx = $this->context();
        $station = $ctx['station'];
        $user = $ctx['user'];

        $card = [
            'station_id' => (string) $station['id'],
            'name' => $station['name'],
            'slug' => $station['slug'],
            'logo_url' => $station['logo_url'] ?? null,
            'frequency' => $station['frequency'] ?? null,
            'company_name' => $station['company_name'] ?? null,
            'contact_name' => $station['contact_name'] ?? null,
            'phone' => $station['contact_phone'] ?? null,
            'email' => $station['contact_email'] ?? null,
            'website' => $station['website'] ?? null,
            'region_code' => $station['region_code'] ?? null,
            'region_name' => $station['region_name'] ?? null,
            'city_name' => $station['city_name'] ?? null,
            'status' => $station['status'] ?? null,
            'is_active' => (bool) ($station['is_active'] ?? true),
            'last_login_at' => $user['last_login_at'] ?? null,
            'last_broadcast_at' => $station['last_broadcast_at'] ?? null,
        ];

        $this->respond(['code' => 0, 'result' => $card, 'message' => 'OK']);
    }

    /**
     * GET /portal/links — eight signed-URL feeds (news/sports/economy/weather/
     * sponsor/ad/special/emergency). Lazy-issues any missing token so the
     * partner panel always has 8 ready URLs.
     */
    public function links(): void
    {
        $ctx = $this->context();
        $stationId = (string) $ctx['station']['id'];
        $tokens = $this->tokenService->ensure($stationId);

        $base = $this->portalBaseUrl();
        $links = [];
        foreach ($tokens as $purpose => $token) {
            $links[] = [
                'purpose' => $purpose,
                'token' => $token,
                'urls' => [
                    'json' => "{$base}/stream/radio/{$stationId}/{$token}/{$purpose}.json",
                    'xml' => "{$base}/stream/radio/{$stationId}/{$token}/{$purpose}.xml",
                    'm3u' => "{$base}/stream/radio/{$stationId}/{$token}/{$purpose}.m3u",
                    'pls' => "{$base}/stream/radio/{$stationId}/{$token}/{$purpose}.pls",
                ],
            ];
        }
        $this->respond(['code' => 0, 'result' => ['links' => $links], 'message' => 'OK']);
    }

    /** GET /portal/feeds — today's scheduled plans for the partner's region. */
    public function feeds(): void
    {
        $ctx = $this->context();
        // Faz 22: ulusal yetkili radyolar tüm bölge planlarını görür.
        $national = (bool) ($ctx['station']['national_access'] ?? false);
        $region = $national ? null : ($ctx['station']['region_code'] ?? null);
        $plans = $this->planRepository->listPlans([
            'date' => $_GET['date'] ?? date('Y-m-d'),
            'region' => $region,
        ]);
        // Strip cross-tenant fields just in case (station_id may point to
        // another station's specific spot).
        $sanitized = array_map(static function (array $p): array {
            unset($p['station_id'], $p['station_slug'], $p['station_name']);
            return $p;
        }, $plans);

        $this->respond(['code' => 0, 'result' => ['plans' => $sanitized], 'message' => 'OK']);
    }

    /**
     * GET /portal/media — recent renderable media for the partner's region,
     * for the indirme merkezi (Download Center). National-access partners
     * (Faz 22) get the cross-region library.
     */
    public function media(): void
    {
        $ctx = $this->context();
        $national = (bool) ($ctx['station']['national_access'] ?? false);
        if ($national) {
            $items = $this->mediaRepository->listLibrary(60);
        } else {
            $regionId = (string) ($ctx['station']['region_id'] ?? '');
            if ($regionId === '') {
                $this->respond(['code' => 0, 'result' => ['items' => []], 'message' => 'OK']);
                return;
            }
            $items = $this->mediaRepository->listByRegion($regionId, 60);
        }
        $this->respond(['code' => 0, 'result' => ['items' => $items], 'message' => 'OK']);
    }

    /** GET /portal/activity — only the partner's own audit log. */
    public function activity(): void
    {
        $ctx = $this->context();
        $stationId = (string) $ctx['station']['id'];
        $logs = $this->auditLogRepository->listLogs([
            'entity_type' => 'station',
            'entity_id' => $stationId,
        ], 100);
        $this->respond(['code' => 0, 'result' => ['logs' => $logs], 'message' => 'OK']);
    }

    /**
     * GET /portal/downloads — Faz 24: master prompt's "Son İndirilen Dosyalar".
     * Pulls the partner's own media_download audit rows from the last 30 days.
     */
    public function downloads(): void
    {
        $ctx = $this->context();
        $username = (string) ($ctx['user']['username'] ?? '');
        if ($username === '') {
            $this->respond(['code' => 0, 'result' => ['downloads' => []], 'message' => 'OK']);
            return;
        }
        $logs = $this->auditLogRepository->listLogs([
            'actor_username' => $username,
            'action' => 'media_download',
            'date_from' => date('Y-m-d', strtotime('-30 days')),
        ], 50);
        $this->respond(['code' => 0, 'result' => ['downloads' => $logs], 'message' => 'OK']);
    }

    /**
     * GET /portal/sponsors — sponsor reads ("sponsor takdimi") relevant to
     * the partner's region (national_access ⇒ all). Listing only — playback
     * still goes through /media-stream/sponsor/{id}.
     */
    public function sponsors(): void
    {
        if ($this->sponsorRepository === null) {
            $this->respond(['code' => 0, 'result' => ['sponsors' => []]]);
            return;
        }
        $ctx = $this->context();
        $national = (bool) ($ctx['station']['national_access'] ?? false);
        $all = $this->sponsorRepository->listAll(200, 0);
        $regionCode = (string) ($ctx['station']['region_code'] ?? '');
        $list = $national ? $all : array_values(array_filter(
            $all,
            static fn (array $s): bool =>
                (bool) ($s['is_global'] ?? false)
                || ((string) ($s['region_code'] ?? '')) === $regionCode
        ));
        // Sponsor rows (non-ad placements only).
        $sponsors = array_values(array_filter(
            $list,
            static fn (array $s): bool => ($s['placement_type'] ?? '') !== 'ad'
        ));
        $this->respond(['code' => 0, 'result' => ['sponsors' => $sponsors]]);
    }

    /**
     * GET /portal/ads — same as sponsors() but for ad-placement rows.
     */
    public function ads(): void
    {
        if ($this->sponsorRepository === null) {
            $this->respond(['code' => 0, 'result' => ['ads' => []]]);
            return;
        }
        $ctx = $this->context();
        $national = (bool) ($ctx['station']['national_access'] ?? false);
        $all = $this->sponsorRepository->listAll(200, 0);
        $regionCode = (string) ($ctx['station']['region_code'] ?? '');
        $list = $national ? $all : array_values(array_filter(
            $all,
            static fn (array $s): bool =>
                (bool) ($s['is_global'] ?? false)
                || ((string) ($s['region_code'] ?? '')) === $regionCode
        ));
        $ads = array_values(array_filter(
            $list,
            static fn (array $s): bool => ($s['placement_type'] ?? '') === 'ad'
        ));
        $this->respond(['code' => 0, 'result' => ['ads' => $ads]]);
    }

    /**
     * Resolve the calling user → station context with tenant isolation:
     * - station_user MUST have a station_id; we load and return it.
     * - admin/manager may call portal endpoints when previewing a partner
     *   view; they must pass ?station_id=… to pick a tenant.
     *
     * @return array{user:array<string,mixed>,station:array<string,mixed>}
     */
    private function context(): array
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if (is_string($token) && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }
        $user = $this->authenticator->authorize($token, 'portal:view');
        $roles = (array) ($user['roles'] ?? []);

        // Partner — locked to their bound station_id.
        if (in_array('station_user', $roles, true)) {
            $stationId = (string) ($user['station_id'] ?? '');
            if ($stationId === '') {
                throw new ForbiddenException('Bu hesap herhangi bir radyoya bağlı değil.');
            }
            $station = $this->stationRepository->findById($stationId);
            if ($station === null) {
                throw new ForbiddenException('Bağlı radyo bulunamadı.');
            }
            return ['user' => $user, 'station' => $station];
        }

        // Admin/manager — must explicitly pick the tenant they want to preview.
        $stationId = $_GET['station_id'] ?? '';
        if (!is_string($stationId) || $stationId === '') {
            throw new UnauthorizedException('station_id parametresi gerekli (admin önizleme).');
        }
        $station = $this->stationRepository->findById($stationId);
        if ($station === null) {
            throw new UnauthorizedException('Radyo bulunamadı.');
        }
        return ['user' => $user, 'station' => $station];
    }

    private function portalBaseUrl(): string
    {
        // Honour a deployed reverse-proxy origin if APP_URL is set, otherwise
        // mirror the inbound request scheme/host so links work locally too.
        $url = getenv('APP_URL');
        if (is_string($url) && trim($url) !== '') {
            return rtrim($url, '/') . '/api/v1';
        }
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}/api/v1";
    }

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
