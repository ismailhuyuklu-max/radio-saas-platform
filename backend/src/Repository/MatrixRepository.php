<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class MatrixRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MediaContentRepository $mediaRepository,
        private readonly SponsorAdRepository $sponsorRepository
    ) {
    }

    public function listRegions(): array
    {
        $stmt = $this->pdo->query('SELECT id, code, name, sort_order FROM regions WHERE is_active = true ORDER BY sort_order ASC, name ASC');
        return $stmt->fetchAll() ?: [];
    }

    public function buildMatrix(): array
    {
        $parts = ['news', 'sports', 'economy', 'weather'];
        $regions = $this->listRegions();
        $matrix = [];

        foreach ($regions as $region) {
            foreach ($parts as $part) {
                $media = $this->mediaRepository->findLatestRenderable((string) $region['id'], $part);
                $sponsor = $this->sponsorRepository->findActiveForRegionAndPart((string) $region['id'], $part);

                $matrix[] = [
                    'regionCode' => $region['code'],
                    'regionName' => $region['name'],
                    'partCode' => $part,
                    'stationSlug' => $media['station_slug'] ?? null,
                    'title' => $media['title'] ?? null,
                    'renderState' => $media['render_state'] ?? 'missing',
                    'hasSponsor' => $sponsor !== null,
                    'sponsorName' => $sponsor['sponsor_name'] ?? null,
                ];
            }
        }

        return $matrix;
    }

    public function listSponsors(mixed $limit = null, mixed $offset = null): array
    {
        return $this->sponsorRepository->listAll($limit, $offset);
    }

    public function deleteSponsor(string $id): void
    {
        $this->sponsorRepository->delete($id);
    }

    public function insertSponsor(array $payload): string
    {
        foreach (['region_id', 'part_code', 'placement', 'sponsor_name', 'asset_bucket', 'asset_key', 'asset_mime'] as $requiredField) {
            if (!isset($payload[$requiredField]) || trim((string) $payload[$requiredField]) === '') {
                throw new \RuntimeException(sprintf('Sponsor field "%s" is required.', $requiredField));
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO sponsors_ads
                (region_id, part_code, placement, placement_type, content_type, is_global, sponsor_name, asset_bucket, asset_key, asset_mime, asset_duration_ms, priority, is_active, starts_at, ends_at)
             VALUES
                (:region_id, :part_code, :placement, :placement_type, :content_type, :is_global, :sponsor_name, :asset_bucket, :asset_key, :asset_mime, :asset_duration_ms, :priority, true, :starts_at, :ends_at)
             RETURNING id'
        );
        $stmt->execute([
            'region_id' => $payload['region_id'],
            'part_code' => $payload['part_code'],
            'placement' => $payload['placement'],
            'placement_type' => $payload['placement_type'] ?? (($payload['placement'] ?? 'pre_roll') === 'post_roll' ? 'outro' : 'intro'),
            'content_type' => $payload['content_type'] ?? ($payload['part_code'] ?? 'news'),
            'is_global' => array_key_exists('is_global', $payload) ? ($payload['is_global'] ? 'true' : 'false') : 'false',
            'sponsor_name' => $payload['sponsor_name'] ?? ($payload['name'] ?? null),
            'asset_bucket' => $payload['asset_bucket'],
            'asset_key' => $payload['asset_key'],
            'asset_mime' => $payload['asset_mime'],
            'asset_duration_ms' => $payload['asset_duration_ms'] ?? 0,
            'priority' => $payload['priority'] ?? 100,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
        ]);

        return (string) $stmt->fetchColumn();
    }
}
