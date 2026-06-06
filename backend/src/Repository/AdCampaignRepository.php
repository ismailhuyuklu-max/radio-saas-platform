<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class AdCampaignRepository
{
    private const MODELS = ['cpm', 'cpp', 'flat'];
    private const STATUSES = ['active', 'paused', 'ended', 'draft'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(mixed $limit = null, mixed $offset = null): array
    {
        [$lim, $off] = \RadioSaaS\Service\Pagination::clamp($limit, $offset);
        $rows = $this->pdo->query(
            'SELECT * FROM ad_campaigns ORDER BY created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off
        )->fetchAll() ?: [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ad_campaigns WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ad_campaigns
                (advertiser_name, sponsor_ad_id, pricing_model, rate, budget, currency,
                 spots_per_day, target_regions, target_parts, starts_at, ends_at, status)
             VALUES
                (:advertiser_name, :sponsor_ad_id, :pricing_model, :rate, :budget, :currency,
                 :spots_per_day, CAST(:target_regions AS jsonb), CAST(:target_parts AS jsonb),
                 :starts_at, :ends_at, :status)
             RETURNING id'
        );
        $stmt->execute($this->params($data));
        $id = (string) $stmt->fetchColumn();
        return $this->findById($id) ?? [];
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        if ($this->findById($id) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ad_campaigns SET
                advertiser_name = :advertiser_name,
                sponsor_ad_id = :sponsor_ad_id,
                pricing_model = :pricing_model,
                rate = :rate,
                budget = :budget,
                currency = :currency,
                spots_per_day = :spots_per_day,
                target_regions = CAST(:target_regions AS jsonb),
                target_parts = CAST(:target_parts AS jsonb),
                starts_at = :starts_at,
                ends_at = :ends_at,
                status = :status,
                updated_at = now()
             WHERE id = :id'
        );
        $params = $this->params($data);
        $params['id'] = $id;
        $stmt->execute($params);
        return $this->findById($id);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ad_campaigns WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function recordAiring(string $campaignId, string $region, string $part, int $impressions): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ad_airings (campaign_id, region_code, part_code, impressions)
             VALUES (:c, :r, :p, :i)'
        );
        $stmt->execute(['c' => $campaignId, 'r' => $region, 'p' => $part, 'i' => $impressions]);
    }

    /**
     * Actual airing totals per campaign.
     *
     * @return array<string, array{spots:int, impressions:int}>
     */
    public function airingTotals(): array
    {
        $rows = $this->pdo->query(
            'SELECT campaign_id, count(*) AS spots, COALESCE(SUM(impressions), 0) AS impressions
             FROM ad_airings GROUP BY campaign_id'
        )->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['campaign_id']] = [
                'spots' => (int) $row['spots'],
                'impressions' => (int) $row['impressions'],
            ];
        }
        return $map;
    }

    /**
     * Scheduled-spot totals per campaign, sourced from content_plans linked by
     * campaign_id. Powers the Reklam Trafik columns:
     *   planned  = all scheduled spots for the campaign
     *   past_due = spots whose air date has already passed (should have aired)
     *
     * @return array<string, array{planned:int, past_due:int}>
     */
    public function planTotals(): array
    {
        $rows = $this->pdo->query(
            "SELECT campaign_id,
                    COUNT(*) AS planned,
                    COUNT(*) FILTER (WHERE plan_date < CURRENT_DATE) AS past_due
             FROM content_plans
             WHERE campaign_id IS NOT NULL
             GROUP BY campaign_id"
        )->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['campaign_id']] = [
                'planned' => (int) $row['planned'],
                'past_due' => (int) $row['past_due'],
            ];
        }
        return $map;
    }

    /**
     * Per-customer (advertiser) breakdown: planned spots from content_plans,
     * aired spots/impressions from ad_airings, and contracted budget. Powers
     * the müşteri report.
     *
     * @return list<array{advertiser_name:string,status:string,budget:float,planned_spots:int,aired_spots:int,impressions:int}>
     */
    public function customerBreakdown(): array
    {
        $sql = <<<'SQL'
            SELECT
                c.advertiser_name,
                c.status,
                c.budget,
                COUNT(DISTINCT cp.id) AS planned_spots,
                COALESCE(a.aired_spots, 0) AS aired_spots,
                COALESCE(a.impressions, 0) AS impressions
            FROM ad_campaigns c
            LEFT JOIN content_plans cp ON cp.campaign_id = c.id
            LEFT JOIN (
                SELECT campaign_id, COUNT(*) AS aired_spots, COALESCE(SUM(impressions), 0) AS impressions
                FROM ad_airings GROUP BY campaign_id
            ) a ON a.campaign_id = c.id
            GROUP BY c.id, c.advertiser_name, c.status, c.budget, a.aired_spots, a.impressions
            ORDER BY c.advertiser_name ASC
        SQL;

        $rows = $this->pdo->query($sql)->fetchAll() ?: [];
        return array_map(static fn (array $r): array => [
            'advertiser_name' => (string) $r['advertiser_name'],
            'status' => (string) $r['status'],
            'budget' => (float) $r['budget'],
            'planned_spots' => (int) $r['planned_spots'],
            'aired_spots' => (int) $r['aired_spots'],
            'impressions' => (int) $r['impressions'],
        ], $rows);
    }

    /**
     * Derive the Tamamlanan / Kalan / Kaçırılan traffic columns for one
     * campaign from its scheduled (planned/past_due) and actual (aired) totals.
     *
     * @param array{planned:int, past_due:int}|null $plan
     * @param array{spots:int, impressions:int}|null $actual
     * @return array{planned:int, aired:int, missed:int, remaining:int, past_due:int, completion_rate:float}
     */
    public static function trafficColumns(?array $plan, ?array $actual): array
    {
        $planned = (int) ($plan['planned'] ?? 0);
        $pastDue = (int) ($plan['past_due'] ?? 0);
        $aired = (int) ($actual['spots'] ?? 0);

        // Missed = past-due spots that never aired; remaining = everything not
        // yet aired or missed. Both clamped to avoid negative columns when
        // automation over-reports airings vs the plan.
        $missed = max(0, $pastDue - $aired);
        $remaining = max(0, $planned - $aired - $missed);
        $completion = $planned > 0 ? round($aired / $planned, 4) : 0.0;

        return [
            'planned' => $planned,
            'aired' => $aired,
            'missed' => $missed,
            'remaining' => $remaining,
            'past_due' => $pastDue,
            'completion_rate' => $completion,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function params(array $data): array
    {
        $model = (string) ($data['pricing_model'] ?? 'cpm');
        $status = (string) ($data['status'] ?? 'active');

        return [
            'advertiser_name' => trim((string) ($data['advertiser_name'] ?? '')),
            'sponsor_ad_id' => ($data['sponsor_ad_id'] ?? null) ?: null,
            'pricing_model' => in_array($model, self::MODELS, true) ? $model : 'cpm',
            'rate' => (float) ($data['rate'] ?? 0),
            'budget' => (float) ($data['budget'] ?? 0),
            'currency' => (string) ($data['currency'] ?? 'TRY'),
            'spots_per_day' => max(0, (int) ($data['spots_per_day'] ?? 1)),
            'target_regions' => json_encode(array_values((array) ($data['target_regions'] ?? []))),
            'target_parts' => json_encode(array_values((array) ($data['target_parts'] ?? []))),
            'starts_at' => (string) ($data['starts_at'] ?? date('Y-m-d')),
            'ends_at' => (string) ($data['ends_at'] ?? date('Y-m-d')),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'active',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['target_regions'] = $this->decodeJson($row['target_regions'] ?? '[]');
        $row['target_parts'] = $this->decodeJson($row['target_parts'] ?? '[]');
        $row['rate'] = (float) ($row['rate'] ?? 0);
        $row['budget'] = (float) ($row['budget'] ?? 0);
        $row['spots_per_day'] = (int) ($row['spots_per_day'] ?? 0);
        return $row;
    }

    /** @return list<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
