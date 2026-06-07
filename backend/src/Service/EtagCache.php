<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Faz CTO-19 — ETag / If-None-Match yardımcısı.
 *
 * GET endpoint'lerinde 304 Not Modified desteği:
 *   1. Controller hesaplama yapmadan ÖNCE: `EtagCache::checkAndShortCircuit($key)`
 *      → request'te If-None-Match varsa ve eşleşiyorsa header set + exit(304)
 *   2. Controller hesaplama sonrası: `EtagCache::emit($etag)` → ETag header
 *
 * ETag stratejisi:
 *   - Stable data (regions, provinces): weak ETag from row count + max(updated_at)
 *   - Volatile data (stations, plans): SHA1(JSON body) — hesaplama sonrası
 *
 * Worker proc paylaşımlı state yok (PHP-FPM stateless); ETag tamamen
 * response-body veya DB metadata'ya bağlı, paylaşım/sync gerekmez.
 */
final class EtagCache
{
    /**
     * If-None-Match request header'ını oku.
     */
    public static function ifNoneMatch(): ?string
    {
        $header = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        if (!is_string($header) || $header === '') {
            return null;
        }
        return trim($header, '"\'');
    }

    /**
     * Weak ETag — table metadata'dan (count + max updated_at).
     * Hesaplama ucuz, DB tek satır SELECT.
     */
    public static function tableEtag(\PDO $pdo, string $table, string $tsColumn = 'updated_at'): string
    {
        try {
            $stmt = $pdo->query(
                "SELECT count(*) AS c, COALESCE(MAX({$tsColumn}), '1970-01-01')::text AS t FROM {$table}"
            );
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$row) {
                return 'empty';
            }
            return 'W/' . substr(md5($table . '|' . $row['c'] . '|' . $row['t']), 0, 16);
        } catch (\Throwable) {
            // updated_at yoksa created_at fallback
            try {
                $stmt = $pdo->query("SELECT count(*) AS c, COALESCE(MAX(created_at), '1970-01-01')::text AS t FROM {$table}");
                $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$row) return 'empty';
                return 'W/' . substr(md5($table . '|' . $row['c'] . '|' . $row['t']), 0, 16);
            } catch (\Throwable) {
                return 'noetag-' . substr(md5($table . (string) microtime(true)), 0, 12);
            }
        }
    }

    /**
     * SHA1 hash of body string — strong ETag.
     */
    public static function bodyEtag(string $body): string
    {
        return substr(sha1($body), 0, 16);
    }

    /**
     * Pragmatic shortcut: hesaplanmış body array'inden ETag üret + check.
     * - Body JSON encode edilir, SHA1 alınır
     * - If-None-Match eşleşirse 304 + boş gövde dönülür
     * - Aksi halde ETag response header'a set edilir, caller body'i yollar
     *
     * Repository PDO accessor gerektirmez — basit pattern.
     * Network tasarrufu var (304: 0 byte body), backend query maliyeti
     * yine ödenir (tableEtag fully cache hit; bodyEtag sadece emit cache).
     *
     * @return bool true = 304 sent, caller exit etmeli; false = devam et
     */
    public static function checkBody(array $body): bool
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $etag = self::bodyEtag($json);
        return self::check($etag);
    }

    /**
     * Verilen etag client'ın If-None-Match'i ile eşleşiyorsa 304 + exit.
     * Aksi halde response header'ında ETag'i set eder.
     *
     * @return bool true = client cache hit, response gönderildi; caller exit etmeli.
     */
    public static function check(string $etag): bool
    {
        $incoming = self::ifNoneMatch();
        if ($incoming !== null && trim($incoming, '"\'') === trim($etag, '"\'')) {
            // 304 — no body, just the ETag header
            http_response_code(304);
            header('ETag: "' . trim($etag, '"\'') . '"');
            header('Cache-Control: private, must-revalidate');
            return true;
        }
        // Cache miss — caller body'i gönderecek; ETag header'ı şimdi set et
        header('ETag: "' . trim($etag, '"\'') . '"');
        header('Cache-Control: private, must-revalidate');
        return false;
    }
}
