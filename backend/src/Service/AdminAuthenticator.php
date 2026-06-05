<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\AdminSessionRepository;
use RuntimeException;

final class AdminAuthenticator
{
    public function __construct(private readonly AdminSessionRepository $sessionRepository)
    {
    }

    public function authenticate(?string $rawToken, array $requiredRoles = ['super', 'radio_manager']): array
    {
        if ($rawToken === null || $rawToken === '') {
            throw new RuntimeException('Admin token is required.');
        }

        $user = $this->sessionRepository->findActiveUserByToken($rawToken);
        if ($user === null) {
            throw new RuntimeException('Invalid or expired admin token.');
        }

        if (array_intersect($requiredRoles, (array) ($user['roles'] ?? [])) === []) {
            throw new RuntimeException('User is not authorized for this operation.');
        }

        return $user;
    }
}
