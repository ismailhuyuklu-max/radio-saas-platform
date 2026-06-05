<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class AdminSessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $userId, int $ttlSeconds = 28800): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_sessions (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, now() + (:ttl_seconds || \' seconds\')::interval)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'ttl_seconds' => $ttlSeconds,
        ]);

        return $rawToken;
    }

    public function findActiveUserByToken(string $rawToken): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*
             FROM admin_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > now()
               AND u.is_active = true
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $rawToken)]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $roles = $row['roles'] ?? [];
        if (is_string($roles)) {
            $decodedRoles = json_decode($roles, true);
            $roles = is_array($decodedRoles) ? $decodedRoles : [];
        }
        $row['roles'] = array_values(array_filter(
            is_array($roles) ? $roles : [],
            static fn (mixed $role): bool => is_string($role) && $role !== ''
        ));

        return $row;
    }
}
