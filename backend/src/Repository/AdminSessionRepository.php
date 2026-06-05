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

    public function revokeByToken(string $rawToken): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = now()
             WHERE token_hash = :token_hash AND revoked_at IS NULL'
        );
        $stmt->execute(['token_hash' => hash('sha256', $rawToken)]);
    }

    /**
     * Active (non-revoked, unexpired) sessions for a user, newest first.
     * The session matching $currentRawToken is flagged is_current.
     *
     * @return list<array<string,mixed>>
     */
    public function listActiveForUser(string $userId, string $currentRawToken): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, expires_at, token_hash
             FROM admin_sessions
             WHERE user_id = :uid AND revoked_at IS NULL AND expires_at > now()
             ORDER BY created_at DESC'
        );
        $stmt->execute(['uid' => $userId]);
        $currentHash = hash('sha256', $currentRawToken);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(static function (array $r) use ($currentHash): array {
            return [
                'id' => (string) $r['id'],
                'created_at' => (string) $r['created_at'],
                'expires_at' => (string) $r['expires_at'],
                'is_current' => hash_equals((string) $r['token_hash'], $currentHash),
            ];
        }, $rows);
    }

    /** Revoke all of a user's sessions except the one matching the raw token. */
    public function revokeAllForUserExcept(string $userId, string $keepRawToken): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = now()
             WHERE user_id = :uid AND revoked_at IS NULL AND token_hash <> :keep'
        );
        $stmt->execute(['uid' => $userId, 'keep' => hash('sha256', $keepRawToken)]);
        return $stmt->rowCount();
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
