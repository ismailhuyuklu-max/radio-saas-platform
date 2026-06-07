<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Faz H3-4 — Trusted proxy aware request introspection.
 *
 * `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Real-IP` header'larına yalnızca
 * TRUSTED_PROXY_IPS env değişkeninde listelenen reverse-proxy IP'lerinden
 * gelen isteklerde GÜVENİRİZ. Aksi halde:
 *   - XFF → spoof, sahte audit log + login throttle bypass
 *   - XFP → mixed-content header injection, rate-limit zaafı
 *
 * Konfigürasyon (.env.production):
 *   TRUSTED_PROXY_IPS=10.0.0.5,10.0.0.6     # virgülle ayrılmış liste
 *   TRUSTED_PROXY_IPS=                       # boş = hiçbir proxy güvenilmiyor
 *
 * Test edilebilirlik için her getter $server dizisini parametre olarak alır;
 * üretimde default $_SERVER kullanılır.
 */
final class RequestContext
{
    /**
     * @return list<string> normalize edilmiş trusted proxy IP listesi (virgülle ayrılmış env).
     */
    public static function trustedProxies(?string $envValue = null): array
    {
        $raw = $envValue ?? (getenv('TRUSTED_PROXY_IPS') ?: '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /**
     * Doğrudan TCP-bağlanan client'in IP'si. Asla spoof edilemez.
     */
    public static function remoteAddress(array $server = null): ?string
    {
        $server ??= $_SERVER;
        $remote = $server['REMOTE_ADDR'] ?? null;
        return is_string($remote) && $remote !== '' ? $remote : null;
    }

    /**
     * Gerçek istemci IP'si:
     *   - TRUSTED proxy'den geliyorsa XFF ilk hop'unu al (ya da X-Real-IP).
     *   - Aksi halde REMOTE_ADDR'a düş — XFF göz ardı edilir.
     */
    public static function clientIp(array $server = null, ?string $trustedEnv = null): ?string
    {
        $server ??= $_SERVER;
        $remote = self::remoteAddress($server);
        if ($remote === null) {
            return null;
        }

        $trusted = self::trustedProxies($trustedEnv);
        if (!in_array($remote, $trusted, true)) {
            // İstek direkt internetten ya da güvenilmeyen bir kaynaktan geldi:
            // XFF spoof edilmiş olabilir → ignore et.
            return $remote;
        }

        // Bilinen bir reverse proxy ardındayız → XFF güvenilir.
        $fwd = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($fwd) && $fwd !== '') {
            // İlk hop = orijinal istemci.
            $first = trim((string) explode(',', $fwd)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $real = $server['HTTP_X_REAL_IP'] ?? '';
        if (is_string($real) && $real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
        return $remote;
    }

    /**
     * Aktif istek scheme'i ('http' | 'https'). Trusted proxy ardındaysa
     * X-Forwarded-Proto'ya güven; aksi halde TLS bağlantısının kendisine.
     */
    public static function scheme(array $server = null, ?string $trustedEnv = null): string
    {
        $server ??= $_SERVER;
        $remote = self::remoteAddress($server);
        $trusted = self::trustedProxies($trustedEnv);

        if ($remote !== null && in_array($remote, $trusted, true)) {
            $xfp = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (is_string($xfp) && $xfp !== '') {
                $first = strtolower(trim((string) explode(',', $xfp)[0]));
                if (in_array($first, ['http', 'https'], true)) {
                    return $first;
                }
            }
        }
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        $port = (int) ($server['SERVER_PORT'] ?? 0);
        return $port === 443 ? 'https' : 'http';
    }

    public static function isSecure(array $server = null, ?string $trustedEnv = null): bool
    {
        return self::scheme($server, $trustedEnv) === 'https';
    }
}
