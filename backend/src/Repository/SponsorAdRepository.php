<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class SponsorAdRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveForRegionAndPart(string $regionId, string $partCode): ?array
    {
        return $this->findBestForRegionAndContent($regionId, $partCode, null);
    }

    public function findBestForRegionAndContent(string $regionId, string $contentType, ?string $placementType = null): ?array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM sponsors_ads
            WHERE content_type = :content_type
              AND is_active = true
              AND (starts_at IS NULL OR starts_at <= now())
              AND (ends_at IS NULL OR ends_at >= now())
              AND (
                    is_global = true
                    OR region_id = :region_id
                  )
        SQL;

        $params = [
            'region_id' => $regionId,
            'content_type' => $contentType,
        ];

        if ($placementType !== null) {
            $sql .= " AND COALESCE(placement_type, CASE WHEN placement = 'post_roll' THEN 'outro' ELSE 'intro' END) = :placement_type";
            $params['placement_type'] = $placementType;
        }

        $sql .= ' ORDER BY CASE WHEN is_global = true THEN 1 ELSE 0 END ASC, priority ASC, created_at DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    public function listAll(mixed $limit = null, mixed $offset = null): array
    {
        [$lim, $off] = \RadioSaaS\Service\Pagination::clamp($limit, $offset);
        $stmt = $this->pdo->query(
            'SELECT sa.*, r.code AS region_code, r.name AS region_name
             FROM sponsors_ads sa
             INNER JOIN regions r ON r.id = sa.region_id
             ORDER BY sa.created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off
        );

        return $stmt->fetchAll() ?: [];
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sponsors_ads WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Resolve a sponsor id to its playable asset object. */
    public function findPlayable(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT asset_bucket, asset_key, asset_mime FROM sponsors_ads WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false || empty($row['asset_key'])) {
            return null;
        }
        return [
            'bucket' => $row['asset_bucket'] ?: 'radio-raw',
            'key' => $row['asset_key'],
            'mime' => $row['asset_mime'] ?: 'audio/mpeg',
        ];
    }

    public function listBestForRegionAndContent(string $regionId, string $contentType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM sponsors_ads
             WHERE content_type = :content_type
               AND is_active = true
               AND (starts_at IS NULL OR starts_at <= now())
               AND (ends_at IS NULL OR ends_at >= now())
               AND (
                    is_global = true
                    OR region_id = :region_id
                  )
             ORDER BY COALESCE(placement_type, CASE WHEN placement = \'post_roll\' THEN \'outro\' ELSE \'intro\' END) ASC,
                      CASE WHEN is_global = true THEN 1 ELSE 0 END ASC,
                      priority ASC,
                      created_at DESC'
        );
        $stmt->execute([
            'region_id' => $regionId,
            'content_type' => $contentType,
        ]);

        return $stmt->fetchAll() ?: [];
    }
}
