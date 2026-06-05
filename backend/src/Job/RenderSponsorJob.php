<?php

declare(strict_types=1);

namespace RadioSaaS\Job;

use PDO;
use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Repository\JobRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\SponsorAdRepository;
use RuntimeException;

final class RenderSponsorJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MinioStorage $storage,
        private readonly MediaContentRepository $mediaRepository,
        private readonly SponsorAdRepository $sponsorRepository,
        private readonly JobRepository $jobRepository
    ) {
    }

    public function handle(array $job): void
    {
        try {
            $payload = is_string($job['payload']) ? json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR) : (array) $job['payload'];
            $media = $this->loadMedia((string) $job['media_content_id']);
            $contentType = (string) ($payload['content_type'] ?? $media['part_code']);
            $regionId = (string) ($payload['region_id'] ?? $media['region_id']);
            $introSponsor = $this->sponsorRepository->findBestForRegionAndContent($regionId, $contentType, 'intro');
            $outroSponsor = $this->sponsorRepository->findBestForRegionAndContent($regionId, $contentType, 'outro');

            if ($introSponsor === null && $outroSponsor === null) {
                $this->mediaRepository->markRendered((string) $media['id'], (string) $media['source_bucket'], (string) $media['source_key'], (string) $media['checksum_sha256']);
                $this->jobRepository->complete((string) $job['id']);
                return;
            }

            $outputKey = sprintf(
                '%s/%s/%s-rendered.%s',
                (string) $media['region_id'],
                (string) $media['part_code'],
                (string) $media['id'],
                $this->guessExtension((string) $media['source_mime'])
            );

            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-render-' . bin2hex(random_bytes(8));
            if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new RuntimeException('Failed to create temp render directory.');
            }

            try {
                $mainInput = $tmpDir . DIRECTORY_SEPARATOR . 'main';
                $introInput = $tmpDir . DIRECTORY_SEPARATOR . 'intro';
                $outroInput = $tmpDir . DIRECTORY_SEPARATOR . 'outro';
                $currentInput = $mainInput;
                $finalOutput = $tmpDir . DIRECTORY_SEPARATOR . 'output.' . $this->guessExtension((string) $media['source_mime']);
                $script = getenv('FFMPEG_RENDER_SCRIPT') ?: '/var/media-tools/ffmpeg/render_ad_injection.sh';

                $this->downloadObject((string) $media['source_bucket'], (string) $media['source_key'], $mainInput);

                if ($introSponsor !== null) {
                    $this->downloadObject((string) $introSponsor['asset_bucket'], (string) $introSponsor['asset_key'], $introInput);
                    $introOutput = $tmpDir . DIRECTORY_SEPARATOR . 'intro-render.' . $this->guessExtension((string) $media['source_mime']);
                    $this->runRenderScript($script, $mainInput, $introInput, $introOutput, 'pre_roll');
                    $currentInput = $introOutput;
                }

                if ($outroSponsor !== null) {
                    $this->downloadObject((string) $outroSponsor['asset_bucket'], (string) $outroSponsor['asset_key'], $outroInput);
                    $this->runRenderScript($script, $currentInput, $outroInput, $finalOutput, 'post_roll');
                    $currentInput = $finalOutput;
                } elseif ($introSponsor !== null) {
                    $finalOutput = $currentInput;
                }

                if (!is_file($currentInput)) {
                    throw new RuntimeException('FFmpeg render did not produce an output file.');
                }

                $this->storage->client()->putObject([
                    'Bucket' => getenv('MINIO_BUCKET_RENDERED') ?: 'radio-rendered',
                    'Key' => $outputKey,
                    'SourceFile' => $currentInput,
                    'ContentType' => (string) $media['source_mime'],
                ]);

                if (filter_var(getenv('FEED_LOCAL_MODE') ?: (getenv('APP_ENV') === 'local' ? '1' : '0'), FILTER_VALIDATE_BOOL)) {
                    $this->storage->client()->putObject([
                        'Bucket' => getenv('MINIO_BUCKET_PUBLIC') ?: 'radio-media',
                        'Key' => $outputKey,
                        'SourceFile' => $currentInput,
                        'ContentType' => (string) $media['source_mime'],
                    ]);
                }

                $checksum = hash_file('sha256', $currentInput) ?: '';
                $this->mediaRepository->markRendered((string) $media['id'], getenv('MINIO_BUCKET_RENDERED') ?: 'radio-rendered', $outputKey, $checksum);
                $this->jobRepository->complete((string) $job['id']);
            } finally {
                $this->deleteDirectory($tmpDir);
            }
        } catch (\Throwable $throwable) {
            $this->jobRepository->fail((string) $job['id'], $throwable->getMessage());
        }
    }

    private function runRenderScript(string $script, string $mainInput, string $adInput, string $outputFile, string $placement): void
    {
        $command = escapeshellarg($script) . ' ' .
            escapeshellarg($mainInput) . ' ' .
            escapeshellarg($adInput) . ' ' .
            escapeshellarg($outputFile) . ' ' .
            escapeshellarg($placement);

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputFile)) {
            throw new RuntimeException('FFmpeg render failed: ' . implode("\n", $output));
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir((string) $file->getRealPath());
                continue;
            }

            @unlink((string) $file->getRealPath());
        }

        @rmdir($directory);
    }

    private function loadMedia(string $mediaContentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media_contents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $mediaContentId]);
        $media = $stmt->fetch();

        if ($media === false) {
            throw new RuntimeException('Media content not found.');
        }

        return $media;
    }

    private function downloadObject(string $bucket, string $key, string $targetPath): void
    {
        $this->storage->client()->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $targetPath,
        ]);
    }

    private function guessExtension(string $mime): string
    {
        return match ($mime) {
            'video/mp4' => 'mp4',
            default => 'mp3',
        };
    }
}
