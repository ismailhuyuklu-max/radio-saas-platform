<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Faz H5-1 — In-memory metrik kaydı + Prometheus text format dump.
 *
 * Bu basit bir PHP-FPM uyumlu metric collector — request scope'unda
 * counter/gauge tutar; Prometheus formatına serileştirir. Persistent
 * sayım için /metrics endpoint'i `php-fpm` worker'larından gelen
 * canlı PG state'i okur (queue depth, job counts).
 *
 * Format örnek (text/plain; version=0.0.4):
 *   # HELP http_requests_total Toplam HTTP istek sayısı (anlık)
 *   # TYPE http_requests_total counter
 *   http_requests_total{method="GET",status="200"} 1
 *
 *   # HELP db_query_count Bu request'te yürütülen DB sorgu sayısı
 *   # TYPE db_query_count gauge
 *   db_query_count 12
 *
 * Üretim notu: PHP-FPM her request için ayrı süreçtir → request-scope
 * counter'ları SHARED memory'e taşımak için apcu/redis gerekir.
 * Bizim ihtiyacımız "anlık scrape sırasındaki DB durumu" → bu yeterli.
 */
final class Metrics
{
    /** @var array<string, array{type:string, help:string, samples: list<array{labels:array<string,string>, value:float|int}>}> */
    private static array $registry = [];

    public static function register(string $name, string $type, string $help): void
    {
        if (isset(self::$registry[$name])) {
            return; // idempotent
        }
        self::$registry[$name] = [
            'type' => $type,
            'help' => $help,
            'samples' => [],
        ];
    }

    public static function gauge(string $name, float|int $value, array $labels = []): void
    {
        if (!isset(self::$registry[$name])) {
            self::register($name, 'gauge', $name);
        }
        self::$registry[$name]['samples'][] = ['labels' => $labels, 'value' => $value];
    }

    public static function counter(string $name, float|int $value = 1, array $labels = []): void
    {
        if (!isset(self::$registry[$name])) {
            self::register($name, 'counter', $name);
        }
        // Sample'ı append et (Prometheus exposition birden çok sample alabilir
        // ve sum hesaplamasını client kütüphanesi yapar; biz aggregate edelim).
        $key = self::labelKey($labels);
        foreach (self::$registry[$name]['samples'] as $i => $s) {
            if (self::labelKey($s['labels']) === $key) {
                self::$registry[$name]['samples'][$i]['value'] = $s['value'] + $value;
                return;
            }
        }
        self::$registry[$name]['samples'][] = ['labels' => $labels, 'value' => $value];
    }

    /**
     * Histogram'lar PHP-FPM single-request modelinde anlamlı değil
     * (her process kendi state'i). Bunun yerine duration_seconds için
     * gauge'a request-elapsed yazıyoruz; scrape sırasındaki son istek.
     */
    public static function histogram(string $name, float $observation, array $labels = []): void
    {
        if (!isset(self::$registry[$name])) {
            self::register($name, 'gauge', $name . ' (last observation)');
        }
        self::gauge($name, $observation, $labels);
    }

    /**
     * Prometheus text exposition format.
     */
    public static function render(): string
    {
        $out = [];
        foreach (self::$registry as $name => $metric) {
            $out[] = "# HELP {$name} {$metric['help']}";
            $out[] = "# TYPE {$name} {$metric['type']}";
            foreach ($metric['samples'] as $sample) {
                $line = $name;
                if ($sample['labels'] !== []) {
                    $parts = [];
                    foreach ($sample['labels'] as $k => $v) {
                        $parts[] = $k . '="' . self::escapeLabel((string) $v) . '"';
                    }
                    $line .= '{' . implode(',', $parts) . '}';
                }
                $line .= ' ' . self::formatValue($sample['value']);
                $out[] = $line;
            }
            $out[] = '';
        }
        return implode("\n", $out);
    }

    public static function resetForTest(): void
    {
        self::$registry = [];
    }

    private static function labelKey(array $labels): string
    {
        ksort($labels);
        return implode('|', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($labels), $labels));
    }

    private static function escapeLabel(string $v): string
    {
        // Prometheus label value spec: \", \\, \n escape.
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v);
    }

    private static function formatValue(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        // Prometheus parser float üzerine %g format kabul eder.
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
