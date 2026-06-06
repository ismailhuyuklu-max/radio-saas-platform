<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RuntimeException;

/**
 * Partner-radio password policy.
 *
 * - Minimum 16 characters
 * - At least one uppercase, lowercase, digit and symbol
 * - Generation rejects ambiguous chars (0/O/1/l/I) so the operator can read
 *   the one-shot password back from the screen without misreading.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 16;

    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';
    private const UPPER = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    private const DIGIT = '23456789';
    private const SYMBOL = '!@#$%^&*?_-+=';

    /**
     * Cryptographically random password meeting the policy. The result is
     * shuffled so the four required character classes are not always in the
     * same order.
     */
    public static function generate(int $length = self::MIN_LENGTH): string
    {
        $length = max(self::MIN_LENGTH, $length);
        $pools = [self::LOWER, self::UPPER, self::DIGIT, self::SYMBOL];
        $chars = [];
        foreach ($pools as $pool) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        $all = self::LOWER . self::UPPER . self::DIGIT . self::SYMBOL;
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        // Fisher-Yates shuffle so the four guaranteed classes aren't at the
        // start. random_int is constant-time-ish; we shuffle in-place.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * Throws RuntimeException with a Turkish operator-friendly message when
     * the password does not satisfy the policy.
     */
    public static function assertStrong(string $pw): void
    {
        if (strlen($pw) < self::MIN_LENGTH) {
            throw new RuntimeException('Şifre en az ' . self::MIN_LENGTH . ' karakter olmalı.');
        }
        if (!preg_match('/[a-z]/', $pw)) {
            throw new RuntimeException('Şifre küçük harf içermeli.');
        }
        if (!preg_match('/[A-Z]/', $pw)) {
            throw new RuntimeException('Şifre büyük harf içermeli.');
        }
        if (!preg_match('/\d/', $pw)) {
            throw new RuntimeException('Şifre rakam içermeli.');
        }
        if (!preg_match('/[^A-Za-z0-9]/', $pw)) {
            throw new RuntimeException('Şifre özel karakter içermeli.');
        }
    }
}
