<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class RefreshTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $userId, string $tokenHash, int $ttlSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO auth_refresh_tokens (user_id, token_hash, expires_at)
             VALUES (:u, :h, now() + (:ttl || ' seconds')::interval)"
        );
        $stmt->execute(['u' => $userId, 'h' => $tokenHash, 'ttl' => $ttlSeconds]);
    }

    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM auth_refresh_tokens
             WHERE token_hash = :h
               AND revoked_at IS NULL
               AND expires_at > now()
             LIMIT 1'
        );
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function revoke(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_refresh_tokens SET revoked_at = now() WHERE token_hash = :h'
        );
        $stmt->execute(['h' => $tokenHash]);
    }

    public function revokeAllForUser(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE auth_refresh_tokens SET revoked_at = now()
             WHERE user_id = :u AND revoked_at IS NULL'
        );
        $stmt->execute(['u' => $userId]);
    }
}
