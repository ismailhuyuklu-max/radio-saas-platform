<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RuntimeException;

/**
 * Centralised Role-Based Access Control.
 *
 * Single source of truth mapping a permission (resource:action) to the roles
 * allowed to perform it. Controllers ask for a permission, never hard-code role
 * lists, so the access policy is auditable in one place and fails closed for
 * unknown permissions.
 *
 * Role hierarchy (highest privilege first):
 *   super         – full control, incl. user/role administration
 *   radio_manager – all broadcast operations (stations, sponsors, planning)
 *   editor        – create/edit content (plans, media) but no infra/admin
 *   viewer        – read-only access to dashboards and listings
 */
final class Rbac
{
    public const ROLE_SUPER = 'super';
    public const ROLE_MANAGER = 'radio_manager';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    /** All assignable roles, highest privilege first. */
    public const ROLES = [
        self::ROLE_SUPER,
        self::ROLE_MANAGER,
        self::ROLE_EDITOR,
        self::ROLE_VIEWER,
    ];

    /** Convenience role groupings used to build the permission map. */
    private const ANY = self::ROLES;
    private const CONTENT_WRITERS = [self::ROLE_SUPER, self::ROLE_MANAGER, self::ROLE_EDITOR];
    private const MANAGERS = [self::ROLE_SUPER, self::ROLE_MANAGER];
    private const ADMINS = [self::ROLE_SUPER];

    /**
     * permission => roles allowed.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [
        // Read access — every authenticated role.
        'matrix:view' => self::ANY,
        'plans:view' => self::ANY,
        'stations:view' => self::ANY,
        'sponsors:view' => self::ANY,

        // Content authoring — editor and up.
        'plans:write' => self::CONTENT_WRITERS,
        'media:write' => self::CONTENT_WRITERS,

        // Broadcast infrastructure — manager and up.
        'matrix:refresh' => self::MANAGERS,
        'stations:write' => self::MANAGERS,
        'stations:delete' => self::MANAGERS,
        'sponsors:write' => self::MANAGERS,
        'audit:view' => self::MANAGERS,

        // User / role administration — super only.
        'users:manage' => self::ADMINS,
    ];

    /**
     * Roles permitted for a permission.
     *
     * @return list<string>
     */
    public static function rolesFor(string $permission): array
    {
        if (!isset(self::PERMISSIONS[$permission])) {
            // Fail closed: an unknown permission grants access to nobody.
            throw new RuntimeException('Unknown permission: ' . $permission);
        }

        return self::PERMISSIONS[$permission];
    }

    /**
     * @param list<string> $userRoles
     */
    public static function allows(array $userRoles, string $permission): bool
    {
        return array_intersect(self::rolesFor($permission), $userRoles) !== [];
    }

    public static function isAssignableRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /**
     * Filters an arbitrary role list down to known, assignable roles.
     *
     * @param list<mixed> $roles
     * @return list<string>
     */
    public static function sanitizeRoles(array $roles): array
    {
        $clean = [];
        foreach ($roles as $role) {
            if (is_string($role) && self::isAssignableRole($role) && !in_array($role, $clean, true)) {
                $clean[] = $role;
            }
        }

        return $clean;
    }
}
