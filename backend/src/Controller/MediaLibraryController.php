<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Exception\NotFoundException;
use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\SponsorAdRepository;
use RadioSaaS\Service\AdminAuthenticator;

/**
 * Media library + in-browser player.
 *
 * - index(): lists all content media (news/sports/economy/weather) and sponsor
 *   ads with a gateway stream URL each.
 * - stream(): an auth-checked, Range-capable proxy that streams the audio from
 *   MinIO through the gateway (same-origin), so the <audio> element can play
 *   and seek any item — including private (radio-raw) sponsor assets.
 */
final class MediaLibraryController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly MediaContentRepository $mediaRepository,
        private readonly SponsorAdRepository $sponsorRepository,
        private readonly MinioStorage $storage,
        private readonly ?AuditLogRepository $auditLogRepository = null
    ) {
    }

    public function index(): void
    {
        $this->guard('matrix:view');

        $content = array_map(static function (array $m): array {
            $slot = $m['slot_time'] ?? null;
            return [
                'id' => (string) $m['id'],
                'kind' => 'content',
                'title' => (string) $m['title'],
                'part_code' => (string) $m['part_code'],
                'slot_time' => $slot ? substr((string) $slot, 0, 5) : null,
                'region_code' => (string) ($m['region_code'] ?? ''),
                'region_name' => (string) ($m['region_name'] ?? ''),
                'render_state' => (string) ($m['render_state'] ?? ''),
                'url' => '/api/v1/media-stream/content/' . $m['id'],
            ];
        }, $this->mediaRepository->listLibrary());

        $sponsors = array_map(static function (array $s): array {
            return [
                'id' => (string) $s['id'],
                'kind' => 'sponsor',
                'title' => (string) ($s['sponsor_name'] ?? ''),
                'part_code' => (string) ($s['content_type'] ?? $s['part_code'] ?? ''),
                'placement_type' => (string) ($s['placement_type'] ?? ''),
                'region_code' => (string) ($s['region_code'] ?? ''),
                'region_name' => (string) ($s['region_name'] ?? ''),
                'is_global' => (bool) ($s['is_global'] ?? false),
                'url' => '/api/v1/media-stream/sponsor/' . $s['id'],
            ];
        }, $this->sponsorRepository->listAll(500, 0));

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['content' => $content, 'sponsors' => $sponsors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function stream(string $kind, string $id): void
    {
        $caller = $this->guard('matrix:view');

        $object = $kind === 'sponsor'
            ? $this->sponsorRepository->findPlayable($id)
            : $this->mediaRepository->findPlayable($id);

        if ($object === null) {
            throw new NotFoundException('Medya bulunamadı.');
        }

        // Faz 21: aktivite kayıtları — Dosya İndirme.
        // Only the FIRST byte-range (i.e. the start of a fresh playback /
        // download) is recorded so HTML5 audio seek-bar requests don't spam
        // the audit table.
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        $isInitialRequest = $range === '' || preg_match('/bytes=0-/', $range);
        if ($isInitialRequest && $this->auditLogRepository !== null) {
            $this->auditLogRepository->log(
                (string) ($caller['username'] ?? 'unknown'),
                'media_download',
                $kind === 'sponsor' ? 'sponsor' : 'media_content',
                $id,
                [
                    'station_id' => (string) ($caller['station_id'] ?? ''),
                    'mime' => (string) ($object['mime'] ?? ''),
                ]
            );
        }

        $params = ['Bucket' => $object['bucket'], 'Key' => $object['key']];
        $status = 200;
        if ($range !== '' && preg_match('/bytes=(\d+)-(\d*)/', $range, $rm)) {
            $params['Range'] = 'bytes=' . $rm[1] . '-' . ($rm[2] !== '' ? $rm[2] : '');
            $status = 206;
        }

        try {
            $obj = $this->storage->client()->getObject($params);
        } catch (\Throwable $e) {
            throw new NotFoundException('Medya akışı alınamadı.');
        }

        http_response_code($status);
        header('Content-Type: ' . (string) ($obj['ContentType'] ?? $object['mime']));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=300');
        if (isset($obj['ContentLength'])) {
            header('Content-Length: ' . (string) $obj['ContentLength']);
        }
        if ($status === 206 && isset($obj['ContentRange'])) {
            header('Content-Range: ' . (string) $obj['ContentRange']);
        }

        $body = $obj['Body'] ?? null;
        if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
            while (!$body->eof()) {
                echo $body->read(65536);
            }
        } else {
            echo (string) $body;
        }
    }

    /** @return array<string,mixed> the authenticated user record */
    private function guard(string $permission): array
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }
        return $this->authenticator->authorize($token, $permission);
    }
}
