<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\PartnerApiKeyRepository;
use RuntimeException;

/**
 * Issue and verify partner API keys. Format:
 *   ak_<station-prefix-8>_<random-48hex>   → 60 chars total
 * Stored as sha256(plaintext) so a DB leak doesn't yield usable keys.
 */
final class ApiKeyService
{
    public function __construct(private readonly PartnerApiKeyRepository $repo)
    {
    }

    /**
     * @param list<string> $scopes
     * @return array{key:string,record:array<string,mixed>}
     */
    public function issue(string $stationId, string $name, array $scopes = [], ?string $createdBy = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('API anahtarı için bir isim gerekli.');
        }
        $stationPrefix = substr(preg_replace('/[^a-z0-9]/', '', strtolower($stationId)) ?? '', 0, 8);
        $random = bin2hex(random_bytes(24));
        $plaintext = "ak_{$stationPrefix}_{$random}";
        // Visible prefix shown in lists (so the partner can identify keys
        // without revealing the secret). Includes "ak_" + 8 char station id.
        $prefix = substr($plaintext, 0, 12);
        $hash = hash('sha256', $plaintext);
        $record = $this->repo->insert($stationId, $name, $hash, $prefix, $scopes, $createdBy);
        return ['key' => $plaintext, 'record' => $record];
    }

    /** Returns the matching key row or null when invalid/revoked. */
    public function verify(string $plaintext, ?string $clientIp = null): ?array
    {
        $hash = hash('sha256', $plaintext);
        $row = $this->repo->findByHash($hash);
        if ($row === null) {
            return null;
        }
        $this->repo->recordUse((string) $row['id'], $clientIp);
        return $row;
    }
}
