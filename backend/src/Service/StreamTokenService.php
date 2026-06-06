<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\StreamTokenRepository;
use RuntimeException;

/**
 * Issues and rotates the eight purpose-keyed stream tokens that back a
 * partner station's signed feed URLs:
 *   /stream/radio/{stationId}/{token}/{purpose}
 *
 * Tokens are 64-char hex (32 random bytes), unguessable in O(2^128).
 */
final class StreamTokenService
{
    public function __construct(private readonly StreamTokenRepository $repo)
    {
    }

    /**
     * Provision (or re-provision) the full set of 8 purpose tokens. Any
     * previously active token for that station is revoked first so cached
     * partner URLs stop working the moment the admin clicks "Rotate".
     *
     * Optional constraints apply to ALL 8 freshly issued tokens:
     *   - ip:      single IP (CIDR not supported) the token may be used from
     *   - domain:  Origin/Referer host the token may be presented through
     *   - expires_at: ISO timestamp after which verify() rejects the token
     *
     * @param array{ip?:?string,domain?:?string,expires_at?:?string} $opts
     * @return array<string,string> purpose => token
     */
    public function rotate(string $stationId, array $opts = []): array
    {
        $this->repo->revokeAll($stationId);
        $ip = $this->cleanString($opts['ip'] ?? null);
        $domain = $this->cleanString($opts['domain'] ?? null);
        $exp = $this->cleanString($opts['expires_at'] ?? null);

        $result = [];
        foreach (StreamTokenRepository::PURPOSES as $purpose) {
            $token = bin2hex(random_bytes(32));
            $this->repo->insert($stationId, $purpose, $token, $ip, $domain, $exp);
            $result[$purpose] = $token;
        }
        return $result;
    }

    private function cleanString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * Lazy issue: ensure the station has a full set of 8 active tokens.
     * Called when a station_user logs in for the first time so the partner
     * portal can always show 8 links without an explicit admin click.
     *
     * @return array<string,string> purpose => token (may include previously-issued tokens)
     */
    public function ensure(string $stationId): array
    {
        $active = $this->repo->listActive($stationId);
        $by = [];
        foreach ($active as $row) {
            $by[(string) $row['purpose']] = (string) $row['token'];
        }
        foreach (StreamTokenRepository::PURPOSES as $purpose) {
            if (!isset($by[$purpose])) {
                $token = bin2hex(random_bytes(32));
                $this->repo->insert($stationId, $purpose, $token);
                $by[$purpose] = $token;
            }
        }
        return $by;
    }

    /**
     * Verify a token presented at /stream/radio/{stationId}/{token}/{purpose}.
     * Returns the token row on success, throws on any mismatch (wrong station,
     * wrong purpose, revoked, expired, IP/domain not whitelisted).
     */
    public function verify(string $stationId, string $purpose, string $token, ?string $clientIp = null, ?string $referer = null): array
    {
        $row = $this->repo->findByToken($token);
        if ($row === null) {
            throw new RuntimeException('Geçersiz veya iptal edilmiş yayın linki.');
        }
        if ((string) $row['station_id'] !== $stationId) {
            throw new RuntimeException('Yayın linki bu radyoya ait değil.');
        }
        if ((string) $row['purpose'] !== $purpose) {
            throw new RuntimeException('Yayın linki bu yayın türü için geçerli değil.');
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('Yayın linkinin süresi doldu.');
        }
        $ipRestriction = $this->cleanString($row['ip_restriction'] ?? null);
        if ($ipRestriction !== null) {
            if ($clientIp === null || !$this->ipMatches($clientIp, $ipRestriction)) {
                throw new RuntimeException('Bu yayın linki yalnızca yetkili IP adresinden çağrılabilir.');
            }
        }
        $domainRestriction = $this->cleanString($row['domain_restriction'] ?? null);
        if ($domainRestriction !== null) {
            $host = $this->refererHost($referer);
            if ($host === null || !$this->hostMatches($host, $domainRestriction)) {
                throw new RuntimeException('Bu yayın linki yalnızca yetkili alan adından çağrılabilir.');
            }
        }
        return $row;
    }

    private function ipMatches(string $client, string $expected): bool
    {
        // Exact match; CIDR could be added here with ip2long+mask if needed.
        return strcasecmp($client, $expected) === 0;
    }

    private function hostMatches(string $host, string $expected): bool
    {
        $host = strtolower($host);
        $expected = strtolower($expected);
        if ($host === $expected) {
            return true;
        }
        // Subdomain rule: "*.example.com" or "example.com" matches sub.example.com.
        if (str_starts_with($expected, '*.')) {
            $suffix = substr($expected, 1); // ".example.com"
            return str_ends_with($host, $suffix);
        }
        return str_ends_with($host, '.' . $expected);
    }

    private function refererHost(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        return is_array($parts) && !empty($parts['host']) ? (string) $parts['host'] : null;
    }
}
