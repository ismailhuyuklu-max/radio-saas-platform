<?php

declare(strict_types=1);

/**
 * Faz H3-5 — PasswordHasher unit tests.
 *
 * Run:  php backend/tests/PasswordHasherTest.php
 */

require __DIR__ . '/../src/Service/PasswordHasher.php';

use RadioSaaS\Service\PasswordHasher;

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

// --------------------------------------------------------------------
// cost() — env override + clamp
// --------------------------------------------------------------------
putenv('BCRYPT_COST');
check(PasswordHasher::cost() === 12, 'default cost = 12');

putenv('BCRYPT_COST=11');
check(PasswordHasher::cost() === 11, 'env override respected');

putenv('BCRYPT_COST=4');
check(PasswordHasher::cost() === 10, 'cost too low (4) clamped to MIN_COST=10');

putenv('BCRYPT_COST=20');
check(PasswordHasher::cost() === 15, 'cost too high (20) clamped to MAX_COST=15');

putenv('BCRYPT_COST=12');

// --------------------------------------------------------------------
// hash() — verifies + applies cost
// --------------------------------------------------------------------
$plain = 'TestPassword!2024';
$hash = PasswordHasher::hash($plain);
check(password_verify($plain, $hash), 'hashed password verifies with password_verify');
$info = password_get_info($hash);
check(($info['options']['cost'] ?? 0) === 12, 'hash carries cost=12');

// --------------------------------------------------------------------
// needsRehash() — eski cost'lu hash upgrade gerekir
// --------------------------------------------------------------------
$oldHash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
check(PasswordHasher::needsRehash($oldHash), 'cost=10 hash needs rehash to env cost=12');

$currentHash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
check(!PasswordHasher::needsRehash($currentHash), 'cost=12 hash does NOT need rehash');

// Tekrar default'a çek — testler arası sızıntı olmasın.
putenv('BCRYPT_COST');

echo "PasswordHasher tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
