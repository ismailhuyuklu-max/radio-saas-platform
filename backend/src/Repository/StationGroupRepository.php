<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class StationGroupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        $sql = <<<'SQL'
            SELECT g.id, g.name, g.description, g.created_at,
                   COUNT(s.id) AS station_count
            FROM station_groups g
            LEFT JOIN stations s ON s.group_id = g.id
            GROUP BY g.id, g.name, g.description, g.created_at
            ORDER BY g.name
        SQL;

        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function create(string $name, ?string $description = null): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO station_groups (name, description)
             VALUES (:name, :description)
             ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description
             RETURNING id'
        );
        $stmt->execute(['name' => $name, 'description' => $description]);
        $id = (string) $stmt->fetchColumn();

        return $this->findById($id) ?? [];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM station_groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Replace the full membership of a group with the given station ids. */
    public function setMembers(string $groupId, array $stationIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $clear = $this->pdo->prepare('UPDATE stations SET group_id = NULL WHERE group_id = :gid');
            $clear->execute(['gid' => $groupId]);

            if ($stationIds !== []) {
                $assign = $this->pdo->prepare('UPDATE stations SET group_id = :gid WHERE id = :sid');
                foreach ($stationIds as $sid) {
                    $assign->execute(['gid' => $groupId, 'sid' => (string) $sid]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<string> station ids belonging to the group */
    public function memberStationIds(string $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM stations WHERE group_id = :gid ORDER BY name');
        $stmt->execute(['gid' => $groupId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function delete(string $groupId): void
    {
        $clear = $this->pdo->prepare('UPDATE stations SET group_id = NULL WHERE group_id = :gid');
        $clear->execute(['gid' => $groupId]);
        $del = $this->pdo->prepare('DELETE FROM station_groups WHERE id = :gid');
        $del->execute(['gid' => $groupId]);
    }
}
