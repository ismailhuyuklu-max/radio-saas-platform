<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class ContentPlanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listPlans(array $filters = []): array
    {
        $sql = <<<'SQL'
            SELECT
                p.*,
                r.code AS region_code,
                r.name AS region_name,
                s.slug AS station_slug,
                s.name AS station_name,
                s.city_name AS station_city_name
            FROM content_plans p
            INNER JOIN regions r ON r.id = p.region_id
            LEFT JOIN stations s ON s.id = p.station_id
            WHERE 1 = 1
        SQL;

        $params = [];

        if (!empty($filters['date'])) {
            $sql .= ' AND p.plan_date = :plan_date';
            $params['plan_date'] = $filters['date'];
        }

        if (!empty($filters['region'])) {
            $sql .= ' AND r.code = :region_code';
            $params['region_code'] = $filters['region'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' ORDER BY p.plan_date DESC, p.slot_time ASC, p.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function listCalendar(array $filters = []): array
    {
        $slots = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
        $plans = $this->listPlans($filters);

        $calendar = [];
        foreach ($slots as $slotTime) {
            $matched = array_values(array_filter(
                $plans,
                static fn (array $plan): bool => substr((string) ($plan['slot_time'] ?? ''), 0, 5) === $slotTime
            ));

            $calendar[] = [
                'slot_time' => $slotTime,
                'status' => empty($matched)
                    ? 'warning'
                    : array_reduce(
                        $matched,
                        static function (string $carry, array $plan): string {
                            $status = (string) ($plan['status'] ?? 'draft');
                            if ($status === 'published') {
                                return 'success';
                            }

                            if ($status === 'running' && $carry !== 'success') {
                                return 'warning';
                            }

                            return $carry;
                        },
                        'danger'
                    ),
                'items' => $matched,
            ];
        }

        return $calendar;
    }

    public function upsert(array $payload): array
    {
        if (!empty($payload['id'])) {
            $stmt = $this->pdo->prepare(
                'UPDATE content_plans
                 SET region_id = :region_id,
                     station_id = :station_id,
                     province = :province,
                     campaign_id = :campaign_id,
                     part_code = :part_code,
                     slot_time = :slot_time,
                     plan_date = :plan_date,
                     content_title = :content_title,
                     content_kind = :content_kind,
                     status = :status,
                     is_global = :is_global,
                     target_regions = CAST(:target_regions AS jsonb),
                     target_parts = CAST(:target_parts AS jsonb),
                     notes = :notes,
                     created_by = :created_by,
                     updated_at = now()
                 WHERE id = :id
                 RETURNING id'
            );
            $stmt->execute($this->bindPlanParams($payload));

            return $this->findById((string) $payload['id']) ?? [];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO content_plans
                (region_id, station_id, province, campaign_id, part_code, slot_time, plan_date, content_title, content_kind, status, is_global, target_regions, target_parts, notes, created_by)
             VALUES
                (:region_id, :station_id, :province, :campaign_id, :part_code, :slot_time, :plan_date, :content_title, :content_kind, :status, :is_global, CAST(:target_regions AS jsonb), CAST(:target_parts AS jsonb), :notes, :created_by)
             RETURNING id'
        );
        $insertParams = $this->bindPlanParams($payload);
        // INSERT statement has no :id placeholder; passing it triggers
        // PDO HY093 "Invalid parameter number" on non-emulated drivers (PostgreSQL).
        unset($insertParams['id']);
        $stmt->execute($insertParams);

        $id = (string) $stmt->fetchColumn();
        return $this->findById($id) ?? [];
    }

    public function findById(string $planId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                p.*,
                r.code AS region_code,
                r.name AS region_name,
                s.slug AS station_slug,
                s.name AS station_name,
                s.city_name AS station_city_name
             FROM content_plans p
             INNER JOIN regions r ON r.id = p.region_id
             LEFT JOIN stations s ON s.id = p.station_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $planId]);

        return $stmt->fetch() ?: null;
    }

    public function hasConflict(array $payload, ?string $ignoreId = null): bool
    {
        // Conflict scope is keyed on region + province + date + slot + part so a
        // city-level plan (province set) only collides with other plans for the
        // same il, while a region-wide plan (province null) collides region-wide.
        $province = $payload['province'] ?? null;
        if (is_string($province) && trim($province) === '') {
            $province = null;
        }

        $sql = <<<'SQL'
            SELECT COUNT(*) AS count
            FROM content_plans
            WHERE region_id = :region_id
              AND COALESCE(province, '') = :province
              AND plan_date = :plan_date
              AND slot_time = :slot_time
              AND part_code = :part_code
        SQL;

        $params = [
            'region_id' => $payload['region_id'],
            'province' => $province ?? '',
            'plan_date' => $payload['plan_date'] ?? date('Y-m-d'),
            'slot_time' => substr((string) ($payload['slot_time'] ?? '08:00'), 0, 5),
            'part_code' => $payload['part_code'],
        ];

        if (!empty($ignoreId)) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $count > 0;
    }

    private function bindPlanParams(array $payload): array
    {
        $slotTime = (string) ($payload['slot_time'] ?? '08:00');
        if (!preg_match('/^\d{2}:\d{2}/', $slotTime)) {
            $slotTime = '08:00';
        }

        $province = $payload['province'] ?? null;
        if (is_string($province) && trim($province) === '') {
            $province = null;
        }
        $campaignId = $payload['campaign_id'] ?? null;
        if (is_string($campaignId) && trim($campaignId) === '') {
            $campaignId = null;
        }

        return [
            'id' => $payload['id'] ?? null,
            'region_id' => $payload['region_id'],
            'station_id' => $payload['station_id'] ?? null,
            'province' => $province,
            'campaign_id' => $campaignId,
            'part_code' => $payload['part_code'],
            'slot_time' => $slotTime,
            'plan_date' => $payload['plan_date'] ?? date('Y-m-d'),
            'content_title' => $payload['content_title'],
            'content_kind' => $payload['content_kind'] ?? ($payload['part_code'] ?? 'news'),
            'status' => $payload['status'] ?? 'draft',
            'is_global' => array_key_exists('is_global', $payload) ? ($payload['is_global'] ? 'true' : 'false') : 'false',
            'target_regions' => json_encode($payload['target_regions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'target_parts' => json_encode($payload['target_parts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'notes' => $payload['notes'] ?? null,
            'created_by' => $payload['created_by'] ?? 'admin',
        ];
    }
}
