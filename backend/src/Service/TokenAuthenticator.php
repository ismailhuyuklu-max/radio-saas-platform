<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\ApiTokenRepository;
use RadioSaaS\Repository\StationRepository;
use RuntimeException;

final class TokenAuthenticator
{
    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly StationRepository $stationRepository
    ) {
    }

    public function authenticate(?string $rawToken): array
    {
        if ($rawToken === null || $rawToken === '') {
            throw new RuntimeException('API token is required.');
        }

        $hash = hash('sha256', $rawToken);
        $token = $this->tokenRepository->findActiveByHash($hash);

        if ($token === null) {
            throw new RuntimeException('Invalid or expired API token.');
        }

        $station = $this->stationRepository->findById((string) $token['station_id']);
        if ($station === null || ($station['status'] ?? null) !== 'active') {
            throw new RuntimeException('Station is not active.');
        }
        $this->tokenRepository->touchLastUsed((string) $token['id']);

        return [
            'token' => $token,
            'station' => $station,
        ];
    }
}
