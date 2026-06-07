<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

/**
 * AdCast Pro Sync Client — per-file download audit trail.
 *
 * Yoğun yazma (500 radyo × 20 dosya/gün × her dosyada en az 2 event = 20K
 * insert/gün). audit_logs'ı bununla doldurmamak için ayrı tablo.
 * Retention: 30 gün (audit-retention.php cron temizler).
 */
final class SyncActivityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *   sync_client_id:int|string,
     *   file_id:string,
     *   file_type:string,
     *   event:string,
     *   bytes_downloaded?:int,
     *   duration_ms?:int,
     *   checksum_ok?:bool|null,
     *   error_message?:string|null
     * } $data
     */
    public function record(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_activity
                (sync_client_id, file_id, file_type, event, bytes_downloaded, duration_ms, checksum_ok, error_message)
             VALUES
                (:sync_client_id, :file_id, :file_type, :event, :bytes, :duration, :checksum, :err)'
        );
        $stmt->execute([
            'sync_client_id' => $data['sync_client_id'],
            'file_id' => $data['file_id'],
            'file_type' => $data['file_type'],
            'event' => $data['event'],
            'bytes' => (int)($data['bytes_downloaded'] ?? 0),
            'duration' => (int)($data['duration_ms'] ?? 0),
            'checksum' => $data['checksum_ok'] ?? null,
            'err' => $data['error_message'] ?? null,
        ]);
    }

    /**
     * Son N aktivite — admin panel drawer için.
     */
    public function recentByClient(string|int $syncClientId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_activity
             WHERE sync_client_id = :id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':id', $syncClientId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function recentByRadio(string|int $radioId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sa.*, sc.user_id, au.username
             FROM sync_activity sa
             INNER JOIN sync_clients sc ON sc.id = sa.sync_client_id
             INNER JOIN admin_users au ON au.id = sc.user_id
             WHERE au.radio_id = :radio_id
             ORDER BY sa.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':radio_id', $radioId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function purgeOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM sync_activity WHERE created_at < NOW() - (:days || ' days')::interval"
        );
        $stmt->execute(['days' => $days]);
        return $stmt->rowCount();
    }
}
