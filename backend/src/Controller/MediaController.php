<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Repository\RegionRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Service\RenderQueueService;
use RadioSaaS\Service\AdminAuthenticator;
use RuntimeException;

final class MediaController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly MediaContentRepository $mediaRepository,
        private readonly RenderQueueService $queue,
        private readonly MinioStorage $storage,
        private readonly RegionRepository $regionRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function upload(): void
    {
        $this->authenticate();

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new RuntimeException('File upload is required.');
        }

        $partCode = (string) ($_POST['part_code'] ?? '');
        $regionInput = trim((string) ($_POST['region_id'] ?? ($_POST['region_code'] ?? '')));
        $title = (string) ($_POST['title'] ?? basename((string) $_FILES['file']['name']));
        $bucket = getenv('MINIO_BUCKET_RAW') ?: 'radio-raw';
        $publicBucket = getenv('MINIO_BUCKET_PUBLIC') ?: 'radio-media';
        $localFeedMode = filter_var(getenv('FEED_LOCAL_MODE') ?: (getenv('APP_ENV') === 'local' ? '1' : '0'), FILTER_VALIDATE_BOOL);
        $regionId = $this->resolveRegionId($regionInput);
        $key = sprintf(
            '%s/%s/%s-%s',
            trim($regionId) ?: 'region',
            trim($partCode) ?: 'part',
            date('YmdHis'),
            basename((string) $_FILES['file']['name'])
        );

        $this->storage->client()->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SourceFile' => $_FILES['file']['tmp_name'],
            'ContentType' => $_FILES['file']['type'] ?: 'application/octet-stream',
        ]);

        if ($localFeedMode) {
            $this->storage->client()->putObject([
                'Bucket' => $publicBucket,
                'Key' => $key,
                'SourceFile' => $_FILES['file']['tmp_name'],
                'ContentType' => $_FILES['file']['type'] ?: 'application/octet-stream',
            ]);
        }

        $mediaContentId = $this->mediaRepository->insert([
            'region_id' => $regionId,
            'station_id' => null,
            'part_code' => $partCode,
            'title' => $title,
            'content_kind' => $partCode,
            'source_bucket' => $bucket,
            'source_key' => $key,
            'source_mime' => (string) ($_FILES['file']['type'] ?: 'application/octet-stream'),
            'source_duration_ms' => (int) ($_POST['duration_ms'] ?? 0),
            'checksum_sha256' => hash_file('sha256', $_FILES['file']['tmp_name']) ?: '',
            'render_state' => 'queued',
            'published_at' => date('c'),
            'effective_from' => date('c'),
        ]);

        $jobId = $this->queue->queueSponsorRender($mediaContentId, [
            'source_bucket' => $bucket,
            'source_key' => $key,
            'title' => $title,
            'region_id' => $regionId,
            'part_code' => $partCode,
        ]);
        $this->auditLogRepository->log('admin', 'upload_media', 'media', $mediaContentId, [
            'region_id' => $regionId,
            'part_code' => $partCode,
            'title' => $title,
            'job_id' => $jobId,
        ]);

        http_response_code(202);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'accepted' => true,
            'media_content_id' => $mediaContentId,
            'job_id' => $jobId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function uploadSponsorAsset(): void
    {
        $this->authenticate();

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new RuntimeException('Sponsor asset upload is required.');
        }

        $bucket = getenv('MINIO_BUCKET_RAW') ?: 'radio-raw';
        $filename = basename((string) $_FILES['file']['name']);
        $key = sprintf('sponsors/%s/%s', bin2hex(random_bytes(12)), $filename);
        $mime = (string) ($_FILES['file']['type'] ?: 'application/octet-stream');

        $this->storage->client()->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SourceFile' => $_FILES['file']['tmp_name'],
            'ContentType' => $mime,
        ]);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'asset_bucket' => $bucket,
            'asset_key' => $key,
            'asset_mime' => $mime,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function resolveRegionId(string $regionInput): string
    {
        if ($regionInput === '') {
            throw new RuntimeException('Region is required.');
        }

        $resolved = $this->regionRepository->findIdByCode($regionInput);
        if ($resolved !== null) {
            return $resolved;
        }

        if (preg_match('/^[0-9a-f-]{36}$/i', $regionInput)) {
            return $regionInput;
        }

        throw new RuntimeException('Region could not be resolved.');
    }

    private function authenticate(): void
    {
        $token = $this->extractToken();
        $this->authenticator->authenticate($token);
    }

    private function extractToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return $_SERVER['HTTP_X_API_TOKEN'] ?? null;
    }
}
