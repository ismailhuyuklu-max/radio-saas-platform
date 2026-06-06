<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RuntimeException;

/**
 * Tiny HS256 JWT issuer/verifier. The master prompt lists JWT + Refresh
 * Token as security requirements; this layer sits ALONGSIDE the existing
 * HttpOnly-cookie session so both flows coexist:
 *
 *   POST /auth/token    → { access (JWT, 15min), refresh (random, 30d) }
 *   POST /auth/refresh  → { access }, refresh rotated
 *   X-Authorization: Bearer eyJhbGciOiJIUzI1NiI...
 *
 * Tokens are signed with APP_KEY; an empty / missing APP_KEY in production
 * makes the service fail closed (issue/verify throw).
 */
final class JwtService
{
    public const ACCESS_TTL_SECONDS = 15 * 60;
    public const REFRESH_TTL_SECONDS = 30 * 86_400;

    public function __construct(private readonly string $appKey)
    {
        $env = getenv('APP_ENV') ?: 'local';
        if ($env === 'production' && trim($appKey) === '') {
            throw new RuntimeException('APP_KEY zorunlu (production JWT issuance).');
        }
    }

    /**
     * Issue an access JWT with sub=userId and the user's roles.
     *
     * @param list<string> $roles
     */
    public function issueAccess(string $userId, array $roles, ?string $stationId = null): string
    {
        $now = time();
        $payload = [
            'iss' => 'aircast-portal',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL_SECONDS,
            'roles' => array_values($roles),
        ];
        if ($stationId !== null && $stationId !== '') {
            $payload['sid'] = $stationId;
        }
        return $this->sign($payload);
    }

    /**
     * Generate an opaque refresh token (NOT a JWT). The DB stores its hash;
     * the caller hands the plaintext to the partner.
     */
    public function issueRefresh(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Verify an access JWT and return its decoded payload. Throws on any
     * signature/format/expiry failure — controllers MUST catch.
     *
     * @return array<string,mixed>
     */
    public function verifyAccess(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Geçersiz JWT formatı.');
        }
        [$h64, $p64, $s64] = $parts;
        $expected = $this->signParts($h64, $p64);
        if (!hash_equals($expected, $s64)) {
            throw new RuntimeException('JWT imzası doğrulanamadı.');
        }
        $payload = json_decode($this->b64UrlDecode($p64) ?: '', true);
        if (!is_array($payload)) {
            throw new RuntimeException('JWT payload bozuk.');
        }
        if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
            throw new RuntimeException('JWT süresi doldu.');
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h64 = $this->b64UrlEncode((string) json_encode($header));
        $p64 = $this->b64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $s64 = $this->signParts($h64, $p64);
        return "{$h64}.{$p64}.{$s64}";
    }

    private function signParts(string $h64, string $p64): string
    {
        $sig = hash_hmac('sha256', "{$h64}.{$p64}", $this->appKey, true);
        return $this->b64UrlEncode($sig);
    }

    private function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $s): string|false
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($s, true);
    }
}
