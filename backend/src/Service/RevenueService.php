<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use DateTimeImmutable;

/**
 * Ad-traffic revenue projections.
 *
 * Pure, deterministic calculations (an explicit "today" is always passed in) so
 * the financial logic is unit-testable. Impressions are ESTIMATES derived from
 * planned spots × per-region reach estimates — not measured listener telemetry,
 * which would require a streaming-analytics pipeline (out of scope, flagged).
 *
 * Pricing models:
 *   cpm  – cost per mille: revenue = impressions / 1000 × rate
 *   cpp  – cost per spot/point: revenue = delivered spots × rate
 *   flat – fixed: revenue = budget (recognised linearly over the run)
 */
final class RevenueService
{
    /** Estimated average daily listeners reachable per region. */
    public const REGION_REACH = [
        'marmara' => 850_000,
        'ege' => 420_000,
        'akdeniz' => 380_000,
        'ic-anadolu' => 520_000,
        'karadeniz' => 300_000,
        'dogu-anadolu' => 180_000,
        'guneydogu-anadolu' => 240_000,
    ];

    /**
     * Sum of reach across the campaign's target regions (unknown regions ignored).
     *
     * @param list<string> $regions
     */
    public static function reachSum(array $regions): int
    {
        $sum = 0;
        foreach ($regions as $region) {
            $sum += self::REGION_REACH[$region] ?? 0;
        }
        return $sum;
    }

    /** Inclusive day count of a campaign window. */
    public static function totalDays(string $startsAt, string $endsAt): int
    {
        $start = new DateTimeImmutable($startsAt);
        $end = new DateTimeImmutable($endsAt);
        if ($end < $start) {
            return 0;
        }
        return (int) $start->diff($end)->days + 1;
    }

    /** Inclusive days elapsed from start through min(today, end). */
    public static function deliveredDays(string $startsAt, string $endsAt, string $today): int
    {
        $start = new DateTimeImmutable($startsAt);
        $end = new DateTimeImmutable($endsAt);
        $now = new DateTimeImmutable($today);
        if ($now < $start) {
            return 0;
        }
        $effectiveEnd = $now < $end ? $now : $end;
        return (int) $start->diff($effectiveEnd)->days + 1;
    }

    private static function revenueFor(string $model, float $rate, float $budget, int $spots, int $impressions): float
    {
        return match ($model) {
            'cpm' => ($impressions / 1000) * $rate,
            'cpp' => $spots * $rate,
            'flat' => $budget,
            default => 0.0,
        };
    }

    /**
     * Per-campaign metrics (delivered = up to today, projected = full run).
     *
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $campaign
     * @param array{spots:int, impressions:int}|null $actual Recorded airings; when
     *        present (spots > 0), delivered figures come from real data instead
     *        of the time-based projection.
     * @return array<string, mixed>
     */
    public static function computeCampaign(array $campaign, string $today, ?array $actual = null): array
    {
        $model = (string) ($campaign['pricing_model'] ?? 'cpm');
        $rate = (float) ($campaign['rate'] ?? 0);
        $budget = (float) ($campaign['budget'] ?? 0);
        $spotsPerDay = max(0, (int) ($campaign['spots_per_day'] ?? 0));
        $regions = array_values((array) ($campaign['target_regions'] ?? []));
        $regionCount = count($regions);
        $reachSum = self::reachSum($regions);

        $startsAt = (string) ($campaign['starts_at'] ?? $today);
        $endsAt = (string) ($campaign['ends_at'] ?? $today);
        $totalDays = self::totalDays($startsAt, $endsAt);
        $deliveredDays = self::deliveredDays($startsAt, $endsAt, $today);

        // spots = per-region daily spots × regions × days
        $projectedSpots = $spotsPerDay * $regionCount * $totalDays;
        $deliveredSpots = $spotsPerDay * $regionCount * $deliveredDays;
        $projectedImpressions = $spotsPerDay * $totalDays * $reachSum;
        $deliveredImpressions = $spotsPerDay * $deliveredDays * $reachSum;

        // flat revenue recognised pro-rata by days for the delivered figure.
        $flatDelivered = $totalDays > 0 ? $budget * ($deliveredDays / $totalDays) : 0.0;

        $projectedRevenue = self::revenueFor($model, $rate, $budget, $projectedSpots, $projectedImpressions);

        // Prefer real recorded airings for the delivered figures when available.
        $hasActuals = $actual !== null && (int) ($actual['spots'] ?? 0) > 0;
        if ($hasActuals) {
            $deliveredSpots = (int) $actual['spots'];
            $deliveredImpressions = (int) $actual['impressions'];
        }

        $deliveredRevenue = $model === 'flat'
            ? $flatDelivered
            : self::revenueFor($model, $rate, $budget, $deliveredSpots, $deliveredImpressions);

        $budgetUsedPct = $budget > 0 ? min(999, ($deliveredRevenue / $budget) * 100) : 0.0;
        $overBudget = $budget > 0 && $deliveredRevenue > $budget;

        return [
            'pricing_model' => $model,
            'total_days' => $totalDays,
            'delivered_days' => $deliveredDays,
            'projected_spots' => $projectedSpots,
            'delivered_spots' => $deliveredSpots,
            'projected_impressions' => $projectedImpressions,
            'delivered_impressions' => $deliveredImpressions,
            'projected_revenue' => round($projectedRevenue, 2),
            'delivered_revenue' => round($deliveredRevenue, 2),
            'budget_used_pct' => round($budgetUsedPct, 1),
            'over_budget' => $overBudget,
            'reach_per_day' => $reachSum * $spotsPerDay,
            'has_actuals' => $hasActuals,
        ];
    }

    /**
     * Aggregate revenue summary across campaigns.
     *
     * @param list<array<string, mixed>> $campaigns
     * @return array<string, mixed>
     */
    /**
     * @param list<array<string, mixed>> $campaigns
     * @param array<string, array{spots:int, impressions:int}> $actualsByCampaign
     * @return array<string, mixed>
     */
    public static function summary(array $campaigns, string $today, array $actualsByCampaign = []): array
    {
        $totalProjected = 0.0;
        $totalDelivered = 0.0;
        $totalImpressions = 0;
        $totalDeliveredImpressions = 0;
        $totalBudget = 0.0;
        $active = 0;
        $byRegion = [];
        $byModel = ['cpm' => 0.0, 'cpp' => 0.0, 'flat' => 0.0];

        foreach ($campaigns as $campaign) {
            $actual = $actualsByCampaign[(string) ($campaign['id'] ?? '')] ?? null;
            $m = self::computeCampaign($campaign, $today, $actual);
            $totalProjected += $m['projected_revenue'];
            $totalDelivered += $m['delivered_revenue'];
            $totalImpressions += $m['projected_impressions'];
            $totalDeliveredImpressions += $m['delivered_impressions'];
            $totalBudget += (float) ($campaign['budget'] ?? 0);
            if (($campaign['status'] ?? '') === 'active') {
                $active++;
            }
            $byModel[$m['pricing_model']] = ($byModel[$m['pricing_model']] ?? 0.0) + $m['delivered_revenue'];

            // Distribute delivered revenue across regions proportional to reach.
            $regions = array_values((array) ($campaign['target_regions'] ?? []));
            $reachSum = self::reachSum($regions);
            foreach ($regions as $region) {
                $share = $reachSum > 0 ? (self::REGION_REACH[$region] ?? 0) / $reachSum : 0;
                $byRegion[$region] = ($byRegion[$region] ?? 0.0) + $m['delivered_revenue'] * $share;
            }
        }

        // Effective CPM = delivered revenue per 1000 delivered impressions.
        $avgCpm = $totalDeliveredImpressions > 0
            ? ($totalDelivered / $totalDeliveredImpressions) * 1000
            : 0.0;

        foreach ($byRegion as $region => $value) {
            $byRegion[$region] = round($value, 2);
        }
        foreach ($byModel as $model => $value) {
            $byModel[$model] = round($value, 2);
        }

        return [
            'total_projected_revenue' => round($totalProjected, 2),
            'total_delivered_revenue' => round($totalDelivered, 2),
            'total_projected_impressions' => $totalImpressions,
            'total_budget' => round($totalBudget, 2),
            'budget_used_pct' => $totalBudget > 0 ? round(($totalDelivered / $totalBudget) * 100, 1) : 0.0,
            'active_campaigns' => $active,
            'campaign_count' => count($campaigns),
            'avg_cpm' => round($avgCpm, 2),
            'revenue_by_region' => $byRegion,
            'revenue_by_model' => $byModel,
        ];
    }
}
