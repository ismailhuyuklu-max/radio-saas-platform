<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\StreamTokenRepository;
use RadioSaaS\Service\MediaFeedService;
use RadioSaaS\Service\StreamTokenService;
use RuntimeException;

/**
 * Signed-URL feed endpoint for partner radios:
 *   GET /stream/radio/{stationId}/{token}/{purpose}.{ext}
 *
 * Verifies (stationId, token, purpose) against station_stream_tokens, then
 * returns a feed bundle in JSON / XML / M3U / PLS or — for "audio" — a
 * streaming MP3. Tokens are revocable: rotation invalidates any cached URL.
 *
 * Unlike the legacy /feeds/{slug}/{part}.{ext} (slug-based, exposes the
 * station's identity), the signed URL leaks nothing guessable.
 */
final class SignedFeedController
{
    public function __construct(
        private readonly StreamTokenRepository $tokenRepository,
        private readonly StreamTokenService $tokenService,
        private readonly StationRepository $stationRepository,
        private readonly MediaFeedService $feedService,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function show(string $stationId, string $token, string $purpose, string $format): void
    {
        // Faz H3-4 — trusted proxy aware; IP kısıtlamalı stream token'lar artık
        // sahte XFF ile bypass edilemez.
        $clientIp = \RadioSaaS\Service\RequestContext::clientIp();
        $referer = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;
        try {
            $row = $this->tokenService->verify($stationId, $purpose, $token, $clientIp, $referer);
        } catch (RuntimeException $e) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }

        $station = $this->stationRepository->findById($stationId);
        if ($station === null || empty($station['slug'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Radyo bulunamadı.']);
            return;
        }

        // Map portal purposes onto the platform's part codes (news/sports/
        // economy/weather → identical, sponsor/ad/special/emergency keep
        // their own bundles).
        $partCode = $this->purposeToPart($purpose);

        try {
            $bundle = $this->feedService->resolve((string) $station['slug'], $partCode);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }

        $this->tokenRepository->recordUse((string) $row['id']);
        $this->auditLogRepository->log(
            (string) ($station['user_id'] ?? 'partner'),
            'partner_stream',
            'station',
            $stationId,
            ['purpose' => $purpose, 'format' => $format]
        );

        switch ($format) {
            case 'json':
                header('Content-Type: application/json; charset=utf-8');
                echo $this->feedService->formatJson($bundle);
                return;
            case 'xml':
                header('Content-Type: application/xml; charset=utf-8');
                echo $this->feedService->formatXml($bundle);
                return;
            case 'm3u':
                header('Content-Type: audio/mpegurl; charset=utf-8');
                echo $this->feedService->formatM3u($bundle);
                return;
            case 'pls':
                header('Content-Type: audio/x-scpls; charset=utf-8');
                echo $this->formatPls($bundle);
                return;
            default:
                http_response_code(415);
                echo json_encode(['error' => 'Unsupported format.']);
                return;
        }
    }

    private function purposeToPart(string $purpose): string
    {
        return match ($purpose) {
            'news', 'sports', 'economy', 'weather' => $purpose,
            // Sponsor/ad bundles aren't separate part codes in the media
            // table; the platform attaches them to the news bundle. The
            // partner still gets a distinct URL — only the underlying feed
            // happens to share the news media set.
            'sponsor', 'ad' => 'news',
            'special' => 'news',
            'emergency' => 'news',
            default => 'news',
        };
    }

    private function formatPls(array $bundle): string
    {
        $name = (string) ($bundle['station']['name'] ?? 'radio');
        $url = (string) ($bundle['stream']['download_url'] ?? '');
        return "[playlist]\nNumberOfEntries=1\nFile1={$url}\nTitle1={$name}\nLength1=-1\nVersion=2\n";
    }
}
