<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\AdminSessionRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RuntimeException;

final class AdminAuthenticator
{
    public function __construct(
        private readonly AdminSessionRepository $sessionRepository,
        private readonly ?AuditLogRepository $auditLogRepository = null
    ) {
    }

    /**
     * Authenticate a session token and require at least one of $requiredRoles.
     *
     * @param list<string> $requiredRoles
     * @return array<string, mixed>
     */
    public function authenticate(?string $rawToken, array $requiredRoles = ['super', 'radio_manager']): array
    {
        if ($rawToken === null || $rawToken === '') {
            throw new RuntimeException('Admin token is required.');
        }

        $user = $this->sessionRepository->findActiveUserByToken($rawToken);
        if ($user === null) {
            throw new RuntimeException('Invalid or expired admin token.');
        }

        $userRoles = (array) ($user['roles'] ?? []);
        if (array_intersect($requiredRoles, $userRoles) === []) {
            // Security observability: record authenticated-but-unauthorized attempts.
            $this->auditLogRepository?->log(
                (string) ($user['username'] ?? 'unknown'),
                'access_denied',
                'auth',
                isset($user['id']) ? (string) $user['id'] : null,
                ['required_roles' => array_values($requiredRoles), 'user_roles' => array_values($userRoles)]
            );

            throw new RuntimeException('User is not authorized for this operation.');
        }

        return $user;
    }

    /**
     * Authenticate and authorize against a named RBAC permission.
     *
     * @return array<string, mixed>
     */
    public function authorize(?string $rawToken, string $permission): array
    {
        return $this->authenticate($rawToken, Rbac::rolesFor($permission));
    }
}
