<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

/**
 * AdCast Pro Sync Client per-machine durum kayıtları.
 *
 * Bir kullanıcı birden çok Windows makinede oturum açabilir (ana studio PC +
 * yedek PC). Her machine_id ayrı kayıttır. `(user_id, machine_id)` üzerinde
 * UNIQUE constraint var — upsert güvenli.
 *
 * Schema: sql/002_sync_clients.sql
 */
final class SyncClientRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Login sırasında veya client startup'ta çağrılır.
     * Aynı (user_id, machine_id) varsa version + ip + last_seen güncellenir.
     * @param array{user_id:int|string,machine_id:string,client_version:string,last_seen_ip?:string|null,last_seen_at:string,user_agent:string} $data
     */
    public function upsert(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_clients (user_id, machine_id, client_version, last_seen_ip, last_seen_at, user_agent)
             VALUES (:user_id, :machine_id, :client_version, :last_seen_ip, :last_seen_at, :user_agent)
             ON CONFLICT (user_id, machine_id) DO UPDATE SET
                 client_version = EXCLUDED.client_version,
                 last_seen_ip = EXCLUDED.last_seen_ip,
                 last_seen_at = EXCLUDED.last_seen_at,
                 user_agent = EXCLUDED.user_agent,
                 updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'machine_id' => $data['machine_id'],
            'client_version' => $data['client_version'],
            'last_seen_ip' => $data['last_seen_ip'] ?? null,
            'last_seen_at' => $data['last_seen_at'],
            'user_agent' => $data['user_agent'] ?? '',
        ]);
    }

    /**
     * Heartbeat — sadece dinamik alanları günceller.
     * machine_id bilinmiyorsa user_id'nin EN SON aktif makinesini günceller.
     * @param array{client_version?:string,os?:string,disk_free_gb?:int,last_seen_ip?:string|null,last_seen_at:string} $data
     */
    public function touch(string|int $userId, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_clients
             SET last_seen_at = :last_seen_at,
                 last_seen_ip = COALESCE(:last_seen_ip, last_seen_ip),
                 client_version = COALESCE(:client_version, client_version),
                 os = COALESCE(:os, os),
                 disk_free_gb = COALESCE(:disk_free_gb, disk_free_gb),
                 updated_at = NOW()
             WHERE user_id = :user_id
               AND last_seen_at = (
                   SELECT MAX(last_seen_at) FROM sync_clients WHERE user_id = :user_id
               )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'last_seen_at' => $data['last_seen_at'],
            'last_seen_ip' => $data['last_seen_ip'] ?? null,
            'client_version' => $data['client_version'] ?? null,
            'os' => $data['os'] ?? null,
            'disk_free_gb' => $data['disk_free_gb'] ?? null,
        ]);
    }

    /**
     * Successful sync tamamlanınca çağrılır.
     */
    public function markSyncCompleted(string|int $userId, int $fileCount): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_clients
             SET last_sync_at = NOW(),
                 last_sync_file_count = :count,
                 last_error = NULL,
                 last_error_at = NULL,
                 updated_at = NOW()
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId, 'count' => $fileCount]);
    }

    public function markError(string|int $userId, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_clients
             SET last_error = :err,
                 last_error_at = NOW(),
                 updated_at = NOW()
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId, 'err' => mb_substr($errorMessage, 0, 1000)]);
    }

    public function findByUserId(string|int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_clients WHERE user_id = :user_id ORDER BY last_seen_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * NOC ekranı için: v_sync_client_status view'inden tüm radyo durumu.
     * Filter: 'all' | 'online' | 'offline' | 'error'
     */
    public function listStatus(string $filter = 'all', int $limit = 200, int $offset = 0): array
    {
        $where = '';
        switch ($filter) {
            case 'online':
                $where = "WHERE connection_status = 'online'";
                break;
            case 'offline':
                $where = "WHERE connection_status = 'offline'";
                break;
            case 'error':
                $where = 'WHERE last_error IS NOT NULL';
                break;
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM v_sync_client_status {$where}
             ORDER BY last_seen_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function countByStatus(): array
    {
        $stmt = $this->pdo->query(
            'SELECT connection_status, COUNT(*) AS cnt
             FROM v_sync_client_status
             GROUP BY connection_status'
        );
        $rows = $stmt->fetchAll() ?: [];
        $result = ['online' => 0, 'stale' => 0, 'offline' => 0, 'with_error' => 0];
        foreach ($rows as $row) {
            $result[(string)$row['connection_status']] = (int)$row['cnt'];
        }
        // Hata sayacı ayrı sorgu (status'ten bağımsız)
        $errStmt = $this->pdo->query('SELECT COUNT(*) FROM v_sync_client_status WHERE last_error IS NOT NULL');
        $result['with_error'] = (int)$errStmt->fetchColumn();
        return $result;
    }
}
