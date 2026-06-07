<?php

declare(strict_types=1);

namespace RadioSaaS\Infrastructure;

use PDO;
use PDOStatement;
use RadioSaaS\Service\Logger;

/**
 * Faz H5-2 — Slow query log decorator.
 *
 * PDO::query() ve prepare()'ı override eder; eşik üstü süren her sorguyu
 * yapısal log'a yazar:
 *   {"level":"warning","msg":"slow query","duration_ms":312,
 *    "sql":"SELECT ...","request_id":"abc..."}
 *
 * Aktivasyon:
 *   .env'de DB_SLOW_QUERY_MS=200  (default kapalı — değer yoksa hiç log yok)
 *
 * Privacy: bound parameter değerleri LOG'A YAZILMAZ (PII riski) — sadece
 * placeholder sayısı ve sql metni gider.
 */
final class LoggingPdo extends PDO
{
    private int $thresholdMs;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        int $thresholdMs = 200
    ) {
        parent::__construct($dsn, $username, $password, $options ?? []);
        $this->thresholdMs = max(1, $thresholdMs);
        // Custom statement class — execute() süresini ölçebilelim.
        $this->setAttribute(
            PDO::ATTR_STATEMENT_CLASS,
            [LoggingStatement::class, [$this->thresholdMs]]
        );
    }

    /**
     * @param string $query
     * @param int|null $fetchMode
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = microtime(true);
        $result = $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
        $this->maybeLog($query, $start);
        return $result;
    }

    public function exec(string $statement): int|false
    {
        $start = microtime(true);
        $result = parent::exec($statement);
        $this->maybeLog($statement, $start);
        return $result;
    }

    private function maybeLog(string $sql, float $startedAt): void
    {
        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        if ($ms < $this->thresholdMs) {
            return;
        }
        // Logger henüz init edilmediyse atla (autoload öncesi exec'ler için).
        if (!class_exists(Logger::class, false)) {
            return;
        }
        Logger::warning('slow query', [
            'duration_ms' => $ms,
            'threshold_ms' => $this->thresholdMs,
            'sql' => $this->shortSql($sql),
        ]);
    }

    public function thresholdMs(): int
    {
        return $this->thresholdMs;
    }

    private function shortSql(string $sql): string
    {
        $compact = preg_replace('/\s+/', ' ', trim($sql));
        $compact ??= $sql;
        if (strlen($compact) > 400) {
            return substr($compact, 0, 400) . '... [truncated]';
        }
        return $compact;
    }
}
