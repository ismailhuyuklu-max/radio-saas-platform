<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Service\MediaFeedService;
use RadioSaaS\Service\TokenAuthenticator;
use RadioSaaS\Repository\AuditLogRepository;
use RuntimeException;

final class FeedController
{
    public function __construct(
        private readonly TokenAuthenticator $authenticator,
        private readonly MediaFeedService $feedService,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function show(string $stationSlug, string $partCode, string $format): void
    {
        $token = $this->extractToken();
        $auth = $this->authenticator->authenticate($token);
        $station = $auth['station'] ?? null;

        if (($station['slug'] ?? null) !== $stationSlug) {
            throw new RuntimeException('Token is not authorized for this station.');
        }

        $bundle = $this->feedService->resolve($stationSlug, $partCode);
        $this->auditLogRepository->log(
            (string) ($station['slug'] ?? 'station'),
            'feed_view',
            'feed',
            $stationSlug . ':' . $partCode,
            ['format' => $format]
        );

        $payload = match ($format) {
            'json' => $this->feedService->formatJson($bundle),
            'xml' => $this->feedService->formatXml($bundle),
            'm3u' => $this->feedService->formatM3u($bundle),
            default => throw new RuntimeException('Unsupported feed format.'),
        };

        $contentType = match ($format) {
            'json' => 'application/json; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'm3u' => 'audio/x-mpegurl; charset=utf-8',
            default => 'application/octet-stream',
        };

        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $payload;
    }

    public function stream(string $stationSlug, string $partCode): void
    {
        $token = $this->extractToken();
        $auth = $this->authenticator->authenticate($token);
        $station = $auth['station'] ?? null;

        if (($station['slug'] ?? null) !== $stationSlug) {
            throw new RuntimeException('Token is not authorized for this station.');
        }

        $bundle = $this->feedService->resolve($stationSlug, $partCode);
        $downloadUrl = (string) ($bundle['stream']['download_url'] ?? '');
        $contentType = (string) ($bundle['stream']['mime'] ?? 'application/octet-stream');
        $expectedBytes = $this->resolveContentLength($downloadUrl);

        $this->auditLogRepository->log(
            (string) ($station['slug'] ?? 'station'),
            'feed_stream_start',
            'feed',
            $stationSlug . ':' . $partCode,
            [
                'download_url' => $downloadUrl,
                'expected_bytes' => $expectedBytes,
            ]
        );

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);

        $source = @fopen($downloadUrl, 'rb', false, $context);
        if ($source === false) {
            $this->auditLogRepository->log(
                (string) ($station['slug'] ?? 'station'),
                'feed_stream_failed',
                'feed',
                $stationSlug . ':' . $partCode,
                ['download_url' => $downloadUrl, 'reason' => 'source_open_failed']
            );
            throw new RuntimeException('Stream source could not be opened.');
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $contentType);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Accel-Buffering: no');
            if ($expectedBytes !== null) {
                header('Content-Length: ' . $expectedBytes);
            }
        }

        $bytesRead = 0;
        while (!feof($source)) {
            $buffer = fread($source, 8192);
            if ($buffer === false) {
                break;
            }

            $length = strlen($buffer);
            if ($length === 0) {
                continue;
            }

            $bytesRead += $length;
            echo $buffer;

            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }

        fclose($source);

        if ($expectedBytes !== null && $bytesRead < $expectedBytes) {
            $this->auditLogRepository->log(
                (string) ($station['slug'] ?? 'station'),
                'feed_stream_failed',
                'feed',
                $stationSlug . ':' . $partCode,
                [
                    'download_url' => $downloadUrl,
                    'expected_bytes' => $expectedBytes,
                    'bytes_read' => $bytesRead,
                    'reason' => 'incomplete_stream',
                ]
            );
            throw new RuntimeException('Stream completed before expected bytes.');
        }

        $this->auditLogRepository->log(
            (string) ($station['slug'] ?? 'station'),
            'feed_stream_complete',
            'feed',
            $stationSlug . ':' . $partCode,
            [
                'download_url' => $downloadUrl,
                'expected_bytes' => $expectedBytes,
                'bytes_read' => $bytesRead,
                'success' => true,
            ]
        );
    }

    private function extractToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return $_SERVER['HTTP_X_API_TOKEN'] ?? null;
    }

    private function resolveContentLength(string $url): ?int
    {
        $headers = @get_headers($url, true);
        if (!is_array($headers)) {
            return null;
        }

        $contentLength = $headers['Content-Length'] ?? $headers['content-length'] ?? null;
        if (is_array($contentLength)) {
            $contentLength = end($contentLength);
        }

        if (is_numeric($contentLength)) {
            return (int) $contentLength;
        }

        return null;
    }
}
