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
     * @return array<string,string> purpose => token
     */
    public function rotate(string $stationId): array
    {
        $this->repo->revokeAll($stationId);
        $result = [];
        foreach (StreamTokenRepository::PURPOSES as $purpose) {
            $token = bin2hex(random_bytes(32));
            $this->repo->insert($stationId, $purpose, $token);
            $result[$purpose] = $token;
        }
        return $result;
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
     * wrong purpose, revoked, expired).
     */
    public function verify(string $stationId, string $purpose, string $token): array
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
        return $row;
    }
}
