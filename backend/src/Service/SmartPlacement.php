<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Smart placement rules engine.
 *
 * Given the plans already scheduled for a region/day, it proposes where to
 * insert sponsor reads and ad spots, and flags scheduling smells (back-to-back
 * ads, missing prime-time content). It is a PURE function over the plan list so
 * it can be unit-tested without a database and reused by the bulk planner.
 */
final class SmartPlacement
{
    /** Prime broadcast slots that should never be left empty. */
    public const PRIME_SLOTS = ['08:00', '12:00', '18:00'];

    /** The seven canonical two-hour news slots of the broadcast day. */
    public const DAY_SLOTS = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];

    public const MAX_ADS_PER_DAY = 12;

    /**
     * @param list<array<string,mixed>> $plans existing plans for one region/day
     * @return array{
     *   suggestions: list<array{slot_time:string,part_code:string,content_title:string,reason:string}>,
     *   warnings: list<array{slot_time:string,message:string}>
     * }
     */
    public static function suggest(array $plans, array $opts = []): array
    {
        $primeSlots = $opts['prime_slots'] ?? self::PRIME_SLOTS;
        $daySlots = $opts['day_slots'] ?? self::DAY_SLOTS;

        // Index plans by slot → list of part_codes present.
        $bySlot = [];
        foreach ($plans as $plan) {
            $slot = self::slot((string) ($plan['slot_time'] ?? ''));
            $part = (string) ($plan['part_code'] ?? 'news');
            $bySlot[$slot][] = $part;
        }

        $suggestions = [];
        $warnings = [];

        // Rule 1 — sponsor after news: every news slot should carry a sponsor
        // read ("Bu haber bülteni … tarafından sunulmuştur").
        foreach ($bySlot as $slot => $parts) {
            if (in_array('news', $parts, true) && !in_array('sponsor', $parts, true)) {
                $suggestions[] = [
                    'slot_time' => $slot,
                    'part_code' => 'sponsor',
                    'content_title' => 'Sponsor takdimi',
                    'reason' => $slot . ' haber bülteni için sponsor takdimi önerildi',
                ];
            }
        }

        // Rule 2 — fill prime gaps: prime slots with no plan at all get a news
        // suggestion so drive-time is never silent.
        foreach ($primeSlots as $slot) {
            if (!isset($bySlot[$slot])) {
                $suggestions[] = [
                    'slot_time' => $slot,
                    'part_code' => 'news',
                    'content_title' => 'Ana haber bülteni',
                    'reason' => $slot . ' prime kuşağı boş — haber bülteni önerildi',
                ];
            }
        }

        // Rule 3 — ad spacing: two ad spots in adjacent slots are a smell.
        $adSlots = [];
        foreach ($bySlot as $slot => $parts) {
            if (in_array('ad', $parts, true)) {
                $adSlots[] = $slot;
            }
        }
        sort($adSlots);
        for ($i = 1, $n = count($adSlots); $i < $n; $i++) {
            $prevIdx = array_search($adSlots[$i - 1], $daySlots, true);
            $curIdx = array_search($adSlots[$i], $daySlots, true);
            if ($prevIdx !== false && $curIdx !== false && ($curIdx - $prevIdx) === 1) {
                $warnings[] = [
                    'slot_time' => $adSlots[$i],
                    'message' => $adSlots[$i - 1] . ' ve ' . $adSlots[$i] . ' kuşaklarında art arda reklam var — araya içerik ekleyin',
                ];
            }
        }

        // Rule 4 — daily ad cap.
        $adCount = count($adSlots);
        $cap = (int) ($opts['max_ads'] ?? self::MAX_ADS_PER_DAY);
        if ($adCount > $cap) {
            $warnings[] = [
                'slot_time' => '',
                'message' => "Günlük reklam sınırı aşıldı ({$adCount}/{$cap}) — bazı spotları başka güne taşıyın",
            ];
        }

        return ['suggestions' => $suggestions, 'warnings' => $warnings];
    }

    private static function slot(string $raw): string
    {
        return substr($raw, 0, 5) ?: '08:00';
    }
}
