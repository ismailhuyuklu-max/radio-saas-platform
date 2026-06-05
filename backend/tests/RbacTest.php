<?php

declare(strict_types=1);

/**
 * Standalone RBAC policy test (no PHPUnit dependency).
 *
 * Run:  php backend/tests/RbacTest.php
 * Exits non-zero if any assertion fails.
 */

require __DIR__ . '/../src/Service/Rbac.php';

use RadioSaaS\Service\Rbac;

$passed = 0;
$failed = 0;

function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$msg}\n");
    }
}

// super has everything
check(Rbac::allows(['super'], 'users:manage'), 'super can manage users');
check(Rbac::allows(['super'], 'stations:delete'), 'super can delete stations');
check(Rbac::allows(['super'], 'matrix:view'), 'super can view matrix');

// radio_manager: broadcast ops but not user admin
check(!Rbac::allows(['radio_manager'], 'users:manage'), 'manager cannot manage users');
check(Rbac::allows(['radio_manager'], 'stations:delete'), 'manager can delete stations');
check(Rbac::allows(['radio_manager'], 'sponsors:write'), 'manager can write sponsors');
check(Rbac::allows(['radio_manager'], 'audit:view'), 'manager can view audit');

// editor: content authoring only
check(Rbac::allows(['editor'], 'plans:write'), 'editor can write plans');
check(Rbac::allows(['editor'], 'media:write'), 'editor can write media');
check(!Rbac::allows(['editor'], 'stations:write'), 'editor cannot write stations');
check(!Rbac::allows(['editor'], 'sponsors:write'), 'editor cannot write sponsors');
check(!Rbac::allows(['editor'], 'audit:view'), 'editor cannot view audit');
check(!Rbac::allows(['editor'], 'users:manage'), 'editor cannot manage users');

// viewer: read-only
check(Rbac::allows(['viewer'], 'matrix:view'), 'viewer can view matrix');
check(Rbac::allows(['viewer'], 'plans:view'), 'viewer can view plans');
check(Rbac::allows(['viewer'], 'stations:view'), 'viewer can view stations');
check(!Rbac::allows(['viewer'], 'plans:write'), 'viewer cannot write plans');
check(!Rbac::allows(['viewer'], 'stations:write'), 'viewer cannot write stations');
check(!Rbac::allows(['viewer'], 'audit:view'), 'viewer cannot view audit');

// multi-role union
check(Rbac::allows(['viewer', 'editor'], 'plans:write'), 'viewer+editor union can write plans');

// role sanitisation
check(
    Rbac::sanitizeRoles(['super', 'bogus', 'viewer', 'viewer']) === ['super', 'viewer'],
    'sanitizeRoles filters unknowns and dedups'
);
check(Rbac::isAssignableRole('editor'), 'editor is assignable');
check(!Rbac::isAssignableRole('root'), 'root is not assignable');

// fail closed on unknown permission
try {
    Rbac::rolesFor('nope:nope');
    check(false, 'unknown permission must throw');
} catch (\Throwable $e) {
    check(true, 'unknown permission throws (fail closed)');
}

echo "Rbac tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
