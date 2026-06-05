<?php

declare(strict_types=1);

namespace RadioSaaS\Infrastructure;

use Aws\S3\S3Client;

final class MinioStorage
{
    private S3Client $client;
    private string $publicBucket;
    private string $publicEndpoint;

    private function __construct(S3Client $client, string $publicBucket, string $publicEndpoint)
    {
        $this->client = $client;
        $this->publicBucket = $publicBucket;
        $this->publicEndpoint = rtrim($publicEndpoint, '/');
    }

    public static function fromEnv(): self
    {
        $endpoint = getenv('MINIO_ENDPOINT') ?: 'http://minio:9000';
        $accessKey = getenv('MINIO_ACCESS_KEY') ?: 'minioadmin';
        $secretKey = getenv('MINIO_SECRET_KEY') ?: 'minioadmin123';
        $region = getenv('MINIO_REGION') ?: 'us-east-1';
        $publicBucket = getenv('MINIO_BUCKET_PUBLIC') ?: 'radio-public';
        $publicEndpoint = getenv('MINIO_PUBLIC_ENDPOINT') ?: $endpoint;

        $client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        return new self($client, $publicBucket, $publicEndpoint);
    }

    public function client(): S3Client
    {
        return $this->client;
    }

    public function publicBucket(): string
    {
        return $this->publicBucket;
    }

    public function presignGetObject(string $bucket, string $key, int $ttlSeconds = 900): string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        $request = $this->client->createPresignedRequest($command, sprintf('+%d seconds', $ttlSeconds));

        return (string) $request->getUri();
    }

    public function publicObjectUrl(string $bucket, string $key): string
    {
        $normalizedKey = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            array_values(array_filter(explode('/', ltrim($key, '/')), static fn (string $segment): bool => $segment !== ''))
        ));

        return sprintf(
            '%s/%s/%s',
            $this->publicEndpoint,
            rawurlencode($bucket),
            $normalizedKey
        );
    }
}
