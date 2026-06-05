<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class RegionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByCode(string $regionCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, sort_order, is_active
             FROM regions
             WHERE code = :code
             LIMIT 1'
        );
        $stmt->execute(['code' => $regionCode]);

        return $stmt->fetch() ?: null;
    }

    public function findIdByCode(string $regionCode): ?string
    {
        $region = $this->findByCode($regionCode);
        if ($region === null) {
            return null;
        }

        return (string) ($region['id'] ?? '');
    }
}
