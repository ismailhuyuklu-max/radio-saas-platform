<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class ProvinceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{name:string,region_code:string,plate:int}> */
    public function listAll(): array
    {
        $rows = $this->pdo->query(
            'SELECT name, region_code, plate FROM provinces ORDER BY name'
        )->fetchAll() ?: [];
        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'region_code' => (string) $r['region_code'],
            'plate' => (int) $r['plate'],
        ], $rows);
    }

    public function regionForProvince(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT region_code FROM provinces WHERE name = :n');
        $stmt->execute(['n' => $name]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : (string) $code;
    }
}
