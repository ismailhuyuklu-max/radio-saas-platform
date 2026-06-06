<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

/**
 * station_stream_tokens — purpose-keyed signed-link tokens per partner radio.
 *
 * Rotation never UPDATEs an existing row; it marks the active token revoked
 * and inserts a new active row. Any partner link cached on a third-party
 * server stops working the moment the row is revoked.
 */
final class StreamTokenRepository
{
    public const PURPOSES = [
        'news', 'sports', 'economy', 'weather',
        'sponsor', 'ad', 'special', 'emergency',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> active tokens for the station */
    public function listActive(string $stationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM station_stream_tokens
             WHERE station_id = :sid AND revoked_at IS NULL
             ORDER BY purpose'
        );
        $stmt->execute(['sid' => $stationId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM station_stream_tokens
             WHERE token = :t AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function insert(string $stationId, string $purpose, string $token, ?string $ip = null, ?string $domain = null, ?string $expiresAt = null): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO station_stream_tokens
                (station_id, purpose, token, ip_restriction, domain_restriction, expires_at)
             VALUES (:sid, :p, :t, :ip, :dom, :exp)
             RETURNING id'
        );
        $stmt->execute([
            'sid' => $stationId,
            'p' => $purpose,
            't' => $token,
            'ip' => $ip,
            'dom' => $domain,
            'exp' => $expiresAt,
        ]);
        return (string) $stmt->fetchColumn();
    }

    /** Revoke every active token belonging to a station (for rotate-tokens). */
    public function revokeAll(string $stationId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE station_stream_tokens
             SET revoked_at = now()
             WHERE station_id = :sid AND revoked_at IS NULL'
        );
        $stmt->execute(['sid' => $stationId]);
        return $stmt->rowCount();
    }

    public function revokeOne(string $stationId, string $purpose): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE station_stream_tokens
             SET revoked_at = now()
             WHERE station_id = :sid AND purpose = :p AND revoked_at IS NULL'
        );
        $stmt->execute(['sid' => $stationId, 'p' => $purpose]);
    }

    public function recordUse(string $tokenId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE station_stream_tokens
             SET last_used_at = now(), use_count = use_count + 1
             WHERE id = :id'
        );
        $stmt->execute(['id' => $tokenId]);
    }
}
