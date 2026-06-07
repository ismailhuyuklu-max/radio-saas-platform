<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Faz H4-1 — Yapısal JSON log + correlation ID.
 *
 * Tek satır, makineyle parse edilebilir log:
 *   {"ts":"2026-06-07T02:30:45+03:00","level":"error",
 *    "msg":"PDOException: ...","request_id":"abc...","path":"/api/v1/plans",
 *    "method":"GET","status":500,"duration_ms":42}
 *
 * X-Request-Id:
 *   - İstemci gönderdiyse onu kabul et (downstream tracing zincirini bozma).
 *   - Yoksa cryptographically random 16 byte hex üret.
 *   - Response header'ında yankıla (debug için kopyalanabilir).
 *
 * Stderr → docker logs → json-file driver (zaten H2-3'te cap'lendi).
 */
final class Logger
{
    private static ?string $requestId = null;
    private static ?float $startedAt = null;

    /**
     * Talep başlangıcında bir kez çağrılır (public/index.php). Idempotent.
     */
    public static function init(array $server = null): string
    {
        $server ??= $_SERVER;
        if (self::$requestId !== null) {
            return self::$requestId;
        }
        self::$startedAt = microtime(true);

        // Client'tan gelen X-Request-Id'yi guard ile kabul et.
        $incoming = $server['HTTP_X_REQUEST_ID'] ?? '';
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $incoming) === 1) {
            self::$requestId = $incoming;
        } else {
            try {
                self::$requestId = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                // random_bytes neredeyse her zaman çalışır; fallback:
                self::$requestId = bin2hex((string) microtime(true)) . dechex(mt_rand());
            }
        }
        return self::$requestId;
    }

    public static function requestId(): string
    {
        return self::$requestId ?? self::init();
    }

    /**
     * Saniye ölçeğinde aktif istek süresi (ms cinsinden int).
     */
    public static function elapsedMs(): int
    {
        if (self::$startedAt === null) {
            return 0;
        }
        return (int) round((microtime(true) - self::$startedAt) * 1000);
    }

    /**
     * Tek satır JSON log → stderr. Container log driver'ı (json-file)
     * bunları rotate edilebilir bir formatta yakalar.
     *
     * level: 'debug' | 'info' | 'warning' | 'error' | 'critical'
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'ts' => date('c'),
            'level' => $level,
            'msg' => $message,
            'request_id' => self::requestId(),
        ];
        foreach ($context as $k => $v) {
            // Anahtar adı reserved alanları override edemesin.
            if (!isset($record[$k])) {
                $record[$k] = $v;
            }
        }

        $json = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($json === false) {
            $json = json_encode([
                'ts' => date('c'),
                'level' => 'error',
                'msg' => 'logger: json_encode failed',
                'request_id' => self::requestId(),
            ]);
        }
        // Direkt PHP error_log → ini_set('error_log','/proc/self/fd/2') altında stderr'a düşer.
        error_log($json);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * Test izolasyonu için — testler arasında state resetlenir.
     */
    public static function resetForTest(): void
    {
        self::$requestId = null;
        self::$startedAt = null;
    }
}
