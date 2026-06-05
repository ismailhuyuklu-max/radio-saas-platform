<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->normalizeRow($row);
    }

    public function findById(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->normalizeRow($row);
    }

    public function findDefaultAdmin(): ?array
    {
        return $this->findByUsername('admin');
    }

    public function touchLastLogin(string $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = now(), updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function listUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, real_name, roles, is_active, mfa_enabled, last_login_at, created_at, updated_at
             FROM users
             ORDER BY created_at ASC, username ASC'
        );

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll() ?: []);
    }

    public function setMfaSecret(string $userId, string $secret): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET mfa_secret = :secret, updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['id' => $userId, 'secret' => $secret]);
    }

    /** @param list<string> $recoveryHashes */
    public function enableMfa(string $userId, array $recoveryHashes): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET mfa_enabled = true,
                 mfa_recovery_codes = CAST(:codes AS jsonb),
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'codes' => json_encode(array_values($recoveryHashes)),
        ]);
    }

    public function disableMfa(string $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users
             SET mfa_enabled = false,
                 mfa_secret = NULL,
                 mfa_recovery_codes = '[]'::jsonb,
                 updated_at = now()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /** Remove a recovery hash if present; returns true when it was consumed. */
    public function consumeRecoveryCode(string $userId, string $codeHash): bool
    {
        $user = $this->findById($userId);
        if ($user === null) {
            return false;
        }
        $codes = (array) ($user['mfa_recovery_codes'] ?? []);
        if (!in_array($codeHash, $codes, true)) {
            return false;
        }
        $remaining = array_values(array_filter($codes, static fn ($c) => $c !== $codeHash));
        $stmt = $this->pdo->prepare(
            'UPDATE users SET mfa_recovery_codes = CAST(:codes AS jsonb), updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['id' => $userId, 'codes' => json_encode($remaining)]);
        return true;
    }

    public function updateRoles(string $userId, array $roles): ?array
    {
        $cleanRoles = array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && trim($role) !== ''
        ));

        if ($cleanRoles === []) {
            $cleanRoles = ['super'];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET roles = CAST(:roles AS jsonb),
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'roles' => json_encode($cleanRoles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->findById($userId);
    }

    public function toggleActive(string $userId, bool $isActive): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET is_active = :is_active,
                 updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'is_active' => $isActive ? 'true' : 'false',
        ]);

        return $this->findById($userId);
    }

    private function normalizeRow(array $row): array
    {
        $roles = $row['roles'] ?? [];

        if (is_string($roles)) {
            $decodedRoles = json_decode($roles, true);
            $roles = is_array($decodedRoles) ? $decodedRoles : [];
        }

        $row['roles'] = array_values(array_filter(
            is_array($roles) ? $roles : [],
            static fn (mixed $role): bool => is_string($role) && $role !== ''
        ));

        if (array_key_exists('mfa_enabled', $row)) {
            $row['mfa_enabled'] = filter_var($row['mfa_enabled'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('mfa_recovery_codes', $row)) {
            $codes = $row['mfa_recovery_codes'];
            if (is_string($codes)) {
                $decoded = json_decode($codes, true);
                $codes = is_array($decoded) ? $decoded : [];
            }
            $row['mfa_recovery_codes'] = is_array($codes) ? array_values($codes) : [];
        }

        return $row;
    }
}
