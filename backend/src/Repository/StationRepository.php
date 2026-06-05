<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class StationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listActiveByRegion(string $regionCode): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.code AS region_code, r.name AS region_name
             FROM stations s
             INNER JOIN regions r ON r.id = s.region_id
             WHERE r.code = :region_code
               AND s.is_active = true
             ORDER BY s.city_name ASC, s.name ASC'
        );
        $stmt->execute(['region_code' => $regionCode]);

        return $stmt->fetchAll() ?: [];
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.code AS region_code, r.name AS region_name
             FROM stations s
             INNER JOIN regions r ON r.id = s.region_id
             WHERE s.slug = :slug
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    public function findBySlugAndRegion(string $regionCode, string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.code AS region_code, r.name AS region_name
             FROM stations s
             INNER JOIN regions r ON r.id = s.region_id
             WHERE r.code = :region_code AND s.slug = :slug
             LIMIT 1'
        );
        $stmt->execute([
            'region_code' => $regionCode,
            'slug' => $slug,
        ]);

        return $stmt->fetch() ?: null;
    }

    public function findById(string $stationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.code AS region_code, r.name AS region_name
             FROM stations s
             INNER JOIN regions r ON r.id = s.region_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $stationId]);

        return $stmt->fetch() ?: null;
    }

    public function listStations(array $filters = []): array
    {
        $sql = <<<'SQL'
            SELECT
                s.*,
                r.code AS region_code,
                r.name AS region_name
            FROM stations s
            INNER JOIN regions r ON r.id = s.region_id
            WHERE 1=1
        SQL;

        $params = [];

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (s.name ILIKE :keyword OR s.slug ILIKE :keyword)';
            $params['keyword'] = '%' . $filters['keyword'] . '%';
        }

        if (!empty($filters['region'])) {
            $sql .= ' AND r.code = :region';
            $params['region'] = $filters['region'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND s.status = :status';
            $params['status'] = $filters['status'];
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $sql .= ' AND s.is_active = :is_active';
            $params['is_active'] = $filters['is_active'] ? 'true' : 'false';
        }

        $sql .= ' ORDER BY s.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function insert(array $row): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stations
                (region_id, name, slug, station_code, status, is_active, city_name, stream_token)
             VALUES
                (:region_id, :name, :slug, :station_code, :status, :is_active, :city_name, :stream_token)
             RETURNING id'
        );
        $stmt->execute([
            'region_id' => $row['region_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'station_code' => $row['station_code'] ?? sprintf('%s-%s', $row['slug'], bin2hex(random_bytes(4))),
            'status' => $row['status'] ?? 'active',
            'is_active' => array_key_exists('is_active', $row) ? ($row['is_active'] ? 'true' : 'false') : 'true',
            'city_name' => $row['city_name'] ?? $row['name'],
            'stream_token' => $row['stream_token'] ?? null,
        ]);

        return (string) $stmt->fetchColumn();
    }

    public function update(string $stationId, array $row): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stations
             SET name = :name,
                 slug = :slug,
                 region_id = :region_id,
                 status = :status,
                 is_active = :is_active,
                 city_name = :city_name,
                 stream_token = :stream_token,
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $stationId,
            'region_id' => $row['region_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'status' => $row['status'] ?? 'active',
            'is_active' => array_key_exists('is_active', $row) ? ($row['is_active'] ? 'true' : 'false') : 'true',
            'city_name' => $row['city_name'] ?? $row['name'],
            'stream_token' => $row['stream_token'] ?? null,
        ]);

        return $this->findById($stationId);
    }

    public function delete(string $stationId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM stations WHERE id = :id');
        $stmt->execute(['id' => $stationId]);
    }

    public function toggleActive(string $stationId, bool $isActive): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stations
             SET is_active = :is_active,
                 status = CASE WHEN :is_active::boolean THEN \'active\' ELSE \'paused\' END,
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $stationId,
            'is_active' => $isActive ? 'true' : 'false',
        ]);

        return $this->findById($stationId);
    }

    public function updateStreamToken(string $stationId, string $streamToken): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stations
             SET stream_token = :stream_token,
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $stationId,
            'stream_token' => $streamToken,
        ]);
    }
}
