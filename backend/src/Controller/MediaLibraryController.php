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

        // Faz 23: format selector. Defaults to mp3 (raw passthrough). m3u/pls
        // return tiny text playlists pointing at the same MP3 URL — handy for
        // partner automation. wav/aac trigger an ffmpeg transcode stream.
        $format = strtolower((string) ($_GET['format'] ?? 'mp3'));
        if (!in_array($format, ['mp3', 'wav', 'aac', 'm3u', 'pls'], true)) {
            $format = 'mp3';
        }
        if ($format === 'm3u' || $format === 'pls') {
            $this->serveTextPlaylist($format, $kind, $id, (string) ($object['title'] ?? 'radio'));
            return;
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

        // Transcode formats (wav/aac) → pipe raw MP3 from MinIO to ffmpeg and
        // stream the encoded output. Range requests are NOT supported on
        // transcoded streams (ffmpeg writes sequentially); we always return 200.
        if ($format === 'wav' || $format === 'aac') {
            $this->serveTranscoded($format, $object, (string) ($object['title'] ?? 'audio'));
            return;
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

    /**
     * Tiny text playlist that points at the same MP3 stream URL. Useful for
     * partner automation that consumes M3U/PLS (e.g. RCS, WideOrbit).
     */
    private function serveTextPlaylist(string $format, string $kind, string $id, string $title): void
    {
        $absolute = $this->absoluteStreamUrl($kind, $id);
        if ($format === 'm3u') {
            header('Content-Type: audio/mpegurl; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$kind}-{$id}.m3u\"");
            echo "#EXTM3U\n";
            echo "#EXTINF:-1,{$title}\n";
            echo $absolute . "\n";
            return;
        }
        // PLS
        header('Content-Type: audio/x-scpls; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$kind}-{$id}.pls\"");
        echo "[playlist]\nNumberOfEntries=1\nFile1={$absolute}\nTitle1={$title}\nLength1=-1\nVersion=2\n";
    }

    /**
     * Stream the MP3 source through ffmpeg and pipe the encoded bytes back
     * to the partner. ffmpeg reads MP3 from stdin (-i pipe:0) and writes the
     * requested codec to stdout (-f wav/adts -). proc_open keeps both pipes
     * non-blocking so we can interleave the MinIO body into stdin while
     * draining stdout to the client.
     */
    private function serveTranscoded(string $format, array $object, string $title): void
    {
        // Pull the entire source object once (network < transcode time). The
        // source is typically a 1-3 MB news bulletin.
        try {
            $obj = $this->storage->client()->getObject([
                'Bucket' => $object['bucket'],
                'Key' => $object['key'],
            ]);
        } catch (\Throwable) {
            throw new NotFoundException('Medya akışı alınamadı.');
        }
        $body = $obj['Body'] ?? null;
        $source = is_object($body) && method_exists($body, 'getContents')
            ? (string) $body->getContents()
            : (string) $body;

        $ext = $format === 'wav' ? 'wav' : 'aac';
        $contentType = $format === 'wav' ? 'audio/wav' : 'audio/aac';
        // ffmpeg target format flag (-f wav | adts for AAC).
        $ffFormat = $format === 'wav' ? 'wav' : 'adts';
        // Reasonable defaults: 16-bit/44.1k stereo WAV, 128k AAC.
        $codecArgs = $format === 'wav'
            ? ['-acodec', 'pcm_s16le', '-ar', '44100', '-ac', '2']
            : ['-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2'];

        $safeTitle = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $title) ?: 'audio';
        header('Content-Type: ' . $contentType);
        header("Content-Disposition: attachment; filename=\"{$safeTitle}.{$ext}\"");
        header('Cache-Control: private, no-store');

        $cmd = array_merge(
            ['ffmpeg', '-loglevel', 'error', '-i', 'pipe:0'],
            $codecArgs,
            ['-f', $ffFormat, 'pipe:1']
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new NotFoundException('Dönüştürücü başlatılamadı.');
        }
        // Write source MP3 in chunks, then close stdin so ffmpeg flushes.
        $written = 0;
        $len = strlen($source);
        while ($written < $len) {
            $chunk = fwrite($pipes[0], substr($source, $written, 65536));
            if ($chunk === false) {
                break;
            }
            $written += $chunk;
        }
        fclose($pipes[0]);

        // Stream stdout straight to the response; drain stderr silently.
        while (!feof($pipes[1])) {
            $bytes = fread($pipes[1], 65536);
            if ($bytes === '' || $bytes === false) {
                break;
            }
            echo $bytes;
            @ob_flush();
            @flush();
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    /**
     * Absolute URL to /media-stream/{kind}/{id}. Prefers APP_URL; otherwise
     * mirrors the inbound request so partner automation can fetch the same
     * link from outside the cluster.
     */
    private function absoluteStreamUrl(string $kind, string $id): string
    {
        $base = getenv('APP_URL');
        if (is_string($base) && trim($base) !== '') {
            return rtrim($base, '/') . "/api/v1/media-stream/{$kind}/{$id}";
        }
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}/api/v1/media-stream/{$kind}/{$id}";
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
