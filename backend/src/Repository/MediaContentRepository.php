<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class MediaContentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Slot-aware lookup: prefer media bound to $slot (e.g. the current 2h news
     * slot); fall back to the latest renderable when no slot media exists.
     */
    public function findRenderableForSlot(string $regionId, string $partCode, ?string $slot): ?array
    {
        if ($slot !== null && $slot !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT m.*, s.slug AS station_slug
                 FROM media_contents m
                 LEFT JOIN stations s ON s.id = m.station_id
                 WHERE m.region_id = :region_id
                   AND m.part_code = :part_code
                   AND m.slot_time = :slot::time
                   AND m.effective_from <= now()
                   AND (m.effective_until IS NULL OR m.effective_until > now())
                 ORDER BY m.effective_from DESC, m.created_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['region_id' => $regionId, 'part_code' => $partCode, 'slot' => $slot]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        return $this->findLatestRenderable($regionId, $partCode);
    }

    /** All media for the admin media-library/player (newest first within type). */
    public function listLibrary(int $limit = 500): array
    {
        $stmt = $this->pdo->query(
            'SELECT m.id, m.title, m.part_code, m.slot_time, m.render_state, m.source_mime,
                    r.code AS region_code, r.name AS region_name, m.created_at
             FROM media_contents m
             INNER JOIN regions r ON r.id = m.region_id
             ORDER BY m.part_code ASC, r.name ASC, m.slot_time ASC NULLS FIRST, m.created_at DESC
             LIMIT ' . max(1, min(2000, $limit))
        );
        return $stmt->fetchAll() ?: [];
    }

    /** Resolve a media id to its playable object (rendered if available, else source). */
    public function findPlayable(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rendered_bucket, rendered_key, source_bucket, source_key, source_mime
             FROM media_contents WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'bucket' => $row['rendered_bucket'] ?: $row['source_bucket'],
            'key' => $row['rendered_key'] ?: $row['source_key'],
            'mime' => $row['source_mime'] ?: 'audio/mpeg',
        ];
    }

    public function findLatestRenderable(string $regionId, string $partCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, s.slug AS station_slug
             FROM media_contents m
             LEFT JOIN stations s ON s.id = m.station_id
             WHERE m.region_id = :region_id
               AND m.part_code = :part_code
               AND m.effective_from <= now()
               AND (m.effective_until IS NULL OR m.effective_until > now())
             ORDER BY m.effective_from DESC, m.created_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'region_id' => $regionId,
            'part_code' => $partCode,
        ]);

        return $stmt->fetch() ?: null;
    }

    public function insert(array $row): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media_contents
                (region_id, station_id, part_code, slot_time, title, content_kind, source_bucket, source_key, source_mime, source_duration_ms, checksum_sha256, render_state, published_at, effective_from)
             VALUES
                (:region_id, :station_id, :part_code, :slot_time, :title, :content_kind, :source_bucket, :source_key, :source_mime, :source_duration_ms, :checksum_sha256, :render_state, :published_at, :effective_from)
             RETURNING id'
        );
        $stmt->execute([
            'region_id' => $row['region_id'],
            'station_id' => $row['station_id'] ?? null,
            'part_code' => $row['part_code'],
            'slot_time' => ($row['slot_time'] ?? null) ?: null,
            'title' => $row['title'],
            'content_kind' => $row['content_kind'],
            'source_bucket' => $row['source_bucket'],
            'source_key' => $row['source_key'],
            'source_mime' => $row['source_mime'],
            'source_duration_ms' => $row['source_duration_ms'] ?? 0,
            'checksum_sha256' => $row['checksum_sha256'],
            'render_state' => $row['render_state'] ?? 'queued',
            'published_at' => $row['published_at'] ?? null,
            'effective_from' => $row['effective_from'] ?? date('c'),
        ]);

        return (string) $stmt->fetchColumn();
    }

    public function markRendered(string $mediaContentId, string $bucket, string $key, string $checksum): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE media_contents
             SET render_state = \'rendered\',
                 rendered_bucket = :bucket,
                 rendered_key = :key,
                 rendered_checksum_sha256 = :checksum,
                 rendered_generated_at = now(),
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $mediaContentId,
            'bucket' => $bucket,
            'key' => $key,
            'checksum' => $checksum,
        ]);
    }

    public function markRenderState(string $mediaContentId, string $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE media_contents SET render_state = :state, updated_at = now() WHERE id = :id');
        $stmt->execute([
            'id' => $mediaContentId,
            'state' => $state,
        ]);
    }
}
