<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

/**
 * partner_api_keys — programmatic access keys for a partner radio's own
 * integrations. Plaintext is shown ONCE (at issue time); the table stores
 * a SHA-256 hash so a database leak does not yield usable keys.
 */
final class PartnerApiKeyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listForStation(string $stationId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT id, station_id, name, key_prefix, scopes, last_used_at,
                       last_used_ip, revoked_at, created_at
                FROM partner_api_keys
                WHERE station_id = :s';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['s' => $stationId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param list<string> $scopes
     */
    public function insert(string $stationId, string $name, string $keyHash, string $prefix, array $scopes, ?string $createdBy = null): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO partner_api_keys (station_id, name, key_hash, key_prefix, scopes, created_by)
             VALUES (:s, :n, :h, :p, CAST(:sc AS jsonb), :cb)
             RETURNING id, station_id, name, key_prefix, scopes, created_at'
        );
        $stmt->execute([
            's' => $stationId,
            'n' => $name,
            'h' => $keyHash,
            'p' => $prefix,
            'sc' => json_encode(array_values($scopes)),
            'cb' => $createdBy,
        ]);
        return $stmt->fetch() ?: [];
    }

    public function findByHash(string $keyHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM partner_api_keys
             WHERE key_hash = :h AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute(['h' => $keyHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function revoke(string $id, string $stationId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE partner_api_keys SET revoked_at = now()
             WHERE id = :id AND station_id = :s AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id, 's' => $stationId]);
        return $stmt->rowCount() > 0;
    }

    public function recordUse(string $id, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE partner_api_keys
             SET last_used_at = now(), last_used_ip = :ip
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'ip' => $ip]);
    }
}
