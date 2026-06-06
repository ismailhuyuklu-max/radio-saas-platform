<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Service\PasswordPolicy;

$passed = 0;
$failed = 0;
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$msg}\n");
    }
}

// Generation respects min length and all four character classes.
for ($i = 0; $i < 25; $i++) {
    $pw = PasswordPolicy::generate();
    check(strlen($pw) >= 16, "iter {$i}: ≥16 chars (got " . strlen($pw) . ')');
    check((bool) preg_match('/[a-z]/', $pw), "iter {$i}: lowercase");
    check((bool) preg_match('/[A-Z]/', $pw), "iter {$i}: uppercase");
    check((bool) preg_match('/\d/', $pw), "iter {$i}: digit");
    check((bool) preg_match('/[^A-Za-z0-9]/', $pw), "iter {$i}: symbol");
}

// Two generations are never equal in a row (collision is statistically zero).
check(PasswordPolicy::generate() !== PasswordPolicy::generate(), 'consecutive generations differ');

// assertStrong rejects weak inputs.
$reject = [
    'short' => 'aB1!aB1!',                  // too short
    'no_upper' => 'abcdefgh1234567!',
    'no_lower' => 'ABCDEFGH1234567!',
    'no_digit' => 'abcdefghABCDEFG!',
    'no_symbol' => 'abcdefghABCDEFG1',
];
foreach ($reject as $name => $pw) {
    $threw = false;
    try {
        PasswordPolicy::assertStrong($pw);
    } catch (\RuntimeException) {
        $threw = true;
    }
    check($threw, "assertStrong rejects {$name}");
}

// Accepts a known-good password.
try {
    PasswordPolicy::assertStrong('xR7@M4!Lp92QzA#x');
    check(true, 'assertStrong accepts strong example');
} catch (\Throwable) {
    check(false, 'assertStrong accepts strong example');
}

echo "\nPasswordPolicy: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
