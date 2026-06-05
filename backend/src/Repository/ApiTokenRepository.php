<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class ApiTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM api_tokens
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > now())
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function touchLastUsed(string $tokenId): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = now() WHERE id = :id');
        $stmt->execute(['id' => $tokenId]);
    }

    public function createForStation(string $stationId, int $ttlDays = 3650): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $prefix = substr($rawToken, 0, 12);

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (station_id, token_hash, token_prefix, scopes, expires_at)
             VALUES (:station_id, :token_hash, :token_prefix, ARRAY[\'feeds:read\', \'streams:read\', \'media:write\'], now() + (:ttl_days || \' days\')::interval)
             RETURNING id'
        );
        $stmt->execute([
            'station_id' => $stationId,
            'token_hash' => $hash,
            'token_prefix' => $prefix,
            'ttl_days' => $ttlDays,
        ]);

        return [
            'id' => (string) $stmt->fetchColumn(),
            'raw_token' => $rawToken,
            'prefix' => $prefix,
        ];
    }
}
