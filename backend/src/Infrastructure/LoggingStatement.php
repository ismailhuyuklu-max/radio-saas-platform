<?php

declare(strict_types=1);

namespace RadioSaaS\Infrastructure;

use PDOStatement;
use RadioSaaS\Service\Logger;

/**
 * Faz H5-2 — Prepared statement süresini ölçer.
 *
 * PDO ATTR_STATEMENT_CLASS ile LoggingPdo tarafından otomatik bağlanır.
 * execute()'a süre ölçer; eşik üstüne çıkarsa Logger::warning.
 */
final class LoggingStatement extends PDOStatement
{
    private int $thresholdMs;

    /**
     * PDO ATTR_STATEMENT_CLASS ctor argümanları konstrüktör parametresi
     * olarak iletilir; PDO Statement reserved fields'a dokunmaz.
     */
    protected function __construct(int $thresholdMs = 200)
    {
        $this->thresholdMs = max(1, $thresholdMs);
    }

    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        $ok = parent::execute($params);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($ms >= $this->thresholdMs && class_exists(Logger::class, false)) {
            Logger::warning('slow query (prepared)', [
                'duration_ms' => $ms,
                'threshold_ms' => $this->thresholdMs,
                'sql' => $this->shortSql((string) $this->queryString),
                // Parametre değerlerini değil sadece count'unu logla — PII koruması.
                'param_count' => is_array($params) ? count($params) : 0,
            ]);
        }

        return $ok;
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
