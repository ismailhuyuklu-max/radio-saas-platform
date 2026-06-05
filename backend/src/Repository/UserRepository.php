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
            'SELECT id, username, real_name, roles, is_active, last_login_at, created_at, updated_at
             FROM users
             ORDER BY created_at ASC, username ASC'
        );

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll() ?: []);
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

        return $row;
    }
}
