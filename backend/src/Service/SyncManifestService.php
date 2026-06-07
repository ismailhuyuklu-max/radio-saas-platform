<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use DateTimeImmutable;
use PDO;

/**
 * Bir radyo için "önümüzdeki 24 saatte yayına girecek" tüm dosyaları toplar.
 *
 * Kaynaklar:
 *  - content_plans (plan_date BETWEEN now AND now+24h) — haber kuşağı dosyaları
 *  - ad_airings (scheduled_at BETWEEN now AND now+24h) — reklam dosyaları
 *  - sponsor_assignments — sponsor intro/outro dosyaları
 *  - media_contents — yukarıdakilerin gerçek dosya metadata'sı
 *
 * Çıktı manifest schema:
 *  {
 *    "file_id": "uuid",
 *    "file_type": "news" | "ad" | "sponsor" | "media_plan",
 *    "filename": "haber_08_marmara_istanbul.mp3",
 *    "size_bytes": 5242880,
 *    "checksum_sha256": "abc123...",
 *    "scheduled_air_time": "2026-06-07T08:00:00+03:00",
 *    "available_from": "2026-06-07T07:00:00+03:00",
 *    "expires_at": "2026-06-07T09:00:00+03:00",
 *    "region": "marmara",
 *    "city": "istanbul",
 *    "radio_id": "123",
 *    "version": "1",
 *    "priority": 10,
 *    "download_url": "/api/v1/sync/download/{file_id}"
 *  }
 */
final class SyncManifestService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Radyo için manifest oluştur.
     *
     * @param string|int $radioId
     * @param string|null $since ISO8601 — diff için (null = full manifest)
     * @return array{generated_at:string,window_start:string,window_end:string,files:list<array<string,mixed>>}
     */
    public function buildForRadio(string|int $radioId, ?string $since = null): array
    {
        $now = new DateTimeImmutable();
        $until = $now->modify('+24 hours');
        $files = [];

        // ----------------------------------------------------------------------
        // 1. News dosyaları — content_plans → media_contents join
        //    Radyo bazlı + ulusal erişimli olan + bölge/şehir scope'unda
        // ----------------------------------------------------------------------
        $news = $this->fetchNewsFiles($radioId, $now, $until, $since);
        foreach ($news as $row) {
            $files[] = $this->normalizeNewsFile($row);
        }

        // ----------------------------------------------------------------------
        // 2. Ad airings — programlanmış reklam dosyaları
        // ----------------------------------------------------------------------
        $ads = $this->fetchAdFiles($radioId, $now, $until, $since);
        foreach ($ads as $row) {
            $files[] = $this->normalizeAdFile($row);
        }

        // ----------------------------------------------------------------------
        // 3. Sponsor dosyaları (intro/outro/spot)
        // ----------------------------------------------------------------------
        $sponsors = $this->fetchSponsorFiles($radioId, $now, $until, $since);
        foreach ($sponsors as $row) {
            $files[] = $this->normalizeSponsorFile($row);
        }

        // Sıralama: scheduled_air_time ASC (yakın zamanlı önce — client priority queue için)
        usort($files, static fn(array $a, array $b) => strcmp($a['scheduled_air_time'], $b['scheduled_air_time']));

        return [
            'generated_at' => $now->format('c'),
            'window_start' => $now->format('c'),
            'window_end' => $until->format('c'),
            'radio_id' => (string)$radioId,
            'file_count' => count($files),
            'files' => $files,
            'next_poll_after' => 60,
        ];
    }

    private function fetchNewsFiles(string|int $radioId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $since): array
    {
        // content_plans: belirli bir radyo + tarih + part_code için planlanmış medya
        // media_contents: dosyanın gerçek metadata'sı (filename, size, checksum)
        $sql = <<<SQL
            SELECT
                mc.id AS file_id,
                mc.filename,
                mc.size_bytes,
                mc.checksum_sha256,
                mc.region,
                mc.province AS city,
                mc.created_at,
                mc.expires_at,
                cp.plan_date,
                cp.slot_time,
                cp.part_code,
                cp.priority,
                cp.version
            FROM content_plans cp
            INNER JOIN media_contents mc ON mc.id = cp.media_content_id
            WHERE cp.radio_id = :radio_id
              AND cp.plan_date BETWEEN :from_date AND :to_date
              AND (cp.slot_time IS NULL OR
                   (cp.plan_date::timestamp + cp.slot_time::interval) BETWEEN :from_ts AND :to_ts)
              AND (mc.expires_at IS NULL OR mc.expires_at > NOW())
        SQL;

        $params = [
            'radio_id' => $radioId,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $to->format('Y-m-d'),
            'from_ts' => $from->format('Y-m-d H:i:s'),
            'to_ts' => $to->format('Y-m-d H:i:s'),
        ];

        if ($since !== null) {
            $sql .= ' AND (mc.created_at > :since OR cp.updated_at > :since)';
            $params['since'] = $since;
        }

        $sql .= ' ORDER BY cp.plan_date ASC, cp.slot_time ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    private function fetchAdFiles(string|int $radioId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $since): array
    {
        $sql = <<<SQL
            SELECT
                mc.id AS file_id,
                mc.filename,
                mc.size_bytes,
                mc.checksum_sha256,
                aa.scheduled_at AS air_time,
                aa.priority,
                aa.version,
                ac.advertiser_name
            FROM ad_airings aa
            INNER JOIN media_contents mc ON mc.id = aa.media_content_id
            LEFT JOIN ad_campaigns ac ON ac.id = aa.campaign_id
            WHERE aa.radio_id = :radio_id
              AND aa.scheduled_at BETWEEN :from_ts AND :to_ts
              AND aa.status IN ('scheduled', 'pending')
        SQL;

        $params = [
            'radio_id' => $radioId,
            'from_ts' => $from->format('Y-m-d H:i:s'),
            'to_ts' => $to->format('Y-m-d H:i:s'),
        ];

        if ($since !== null) {
            $sql .= ' AND (mc.created_at > :since OR aa.updated_at > :since)';
            $params['since'] = $since;
        }

        $sql .= ' ORDER BY aa.scheduled_at ASC';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            // ad_airings tablosu yoksa veya kolon farklıysa — silent fallback,
            // sync client çalışmaya devam etmeli (haber dosyaları kritik)
            return [];
        }
    }

    private function fetchSponsorFiles(string|int $radioId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $since): array
    {
        // Sponsor assignments — radyo + tarih scope'u
        // (sponsor_assignments tablosu Faz 3-MVP'de henüz yok olabilir;
        // try/catch ile silent skip)
        $sql = <<<SQL
            SELECT
                mc.id AS file_id,
                mc.filename,
                mc.size_bytes,
                mc.checksum_sha256,
                sa.placement_type,
                sa.priority,
                sa.version
            FROM sponsor_assignments sa
            INNER JOIN media_contents mc ON mc.id = sa.media_content_id
            WHERE sa.radio_id = :radio_id
              AND sa.active = TRUE
              AND (sa.expires_at IS NULL OR sa.expires_at > NOW())
        SQL;

        $params = ['radio_id' => $radioId];

        if ($since !== null) {
            $sql .= ' AND (mc.created_at > :since OR sa.updated_at > :since)';
            $params['since'] = $since;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function normalizeNewsFile(array $row): array
    {
        $airTime = $this->buildAirTime((string)$row['plan_date'], (string)($row['slot_time'] ?? '00:00:00'));
        return [
            'file_id' => (string)$row['file_id'],
            'file_type' => 'news',
            'filename' => (string)$row['filename'],
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'checksum_sha256' => (string)($row['checksum_sha256'] ?? ''),
            'scheduled_air_time' => $airTime,
            'available_from' => $this->shiftIsoTime($airTime, '-1 hour'),
            'expires_at' => (string)($row['expires_at'] ?? $this->shiftIsoTime($airTime, '+2 hours')),
            'region' => (string)($row['region'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'part_code' => (string)($row['part_code'] ?? ''),
            'version' => (string)($row['version'] ?? '1'),
            'priority' => (int)($row['priority'] ?? 10),
            'download_url' => '/api/v1/sync/download/' . (string)$row['file_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeAdFile(array $row): array
    {
        return [
            'file_id' => (string)$row['file_id'],
            'file_type' => 'ad',
            'filename' => (string)$row['filename'],
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'checksum_sha256' => (string)($row['checksum_sha256'] ?? ''),
            'scheduled_air_time' => (string)$row['air_time'],
            'available_from' => $this->shiftIsoTime((string)$row['air_time'], '-2 hours'),
            'expires_at' => $this->shiftIsoTime((string)$row['air_time'], '+1 hour'),
            'advertiser' => (string)($row['advertiser_name'] ?? ''),
            'version' => (string)($row['version'] ?? '1'),
            'priority' => (int)($row['priority'] ?? 5),
            'download_url' => '/api/v1/sync/download/' . (string)$row['file_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeSponsorFile(array $row): array
    {
        return [
            'file_id' => (string)$row['file_id'],
            'file_type' => 'sponsor',
            'filename' => (string)$row['filename'],
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'checksum_sha256' => (string)($row['checksum_sha256'] ?? ''),
            'scheduled_air_time' => (new DateTimeImmutable())->format('c'), // Sponsor "her zaman" hazır
            'available_from' => (new DateTimeImmutable())->format('c'),
            'expires_at' => (new DateTimeImmutable())->modify('+30 days')->format('c'),
            'placement_type' => (string)($row['placement_type'] ?? 'intro'),
            'version' => (string)($row['version'] ?? '1'),
            'priority' => (int)($row['priority'] ?? 1),
            'download_url' => '/api/v1/sync/download/' . (string)$row['file_id'],
        ];
    }

    private function buildAirTime(string $planDate, string $slotTime): string
    {
        try {
            return (new DateTimeImmutable($planDate . ' ' . $slotTime))->format('c');
        } catch (\Exception $e) {
            return (new DateTimeImmutable())->format('c');
        }
    }

    private function shiftIsoTime(string $iso, string $modifier): string
    {
        try {
            return (new DateTimeImmutable($iso))->modify($modifier)->format('c');
        } catch (\Exception $e) {
            return $iso;
        }
    }
}
