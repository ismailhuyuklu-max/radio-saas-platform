<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Time-based One-Time Password (TOTP, RFC 6238) for two-factor auth.
 *
 * Pure and deterministic (timestamp always passed in) so it can be verified
 * against the RFC 6238 test vectors. SHA1 / 6 digits / 30s period — the
 * settings every authenticator app (Google Authenticator, Authy, 1Password)
 * uses by default.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a new random base32 secret (default 160-bit). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** The current code for a secret at a given unix timestamp. */
    public static function codeAt(string $base32Secret, int $timestamp): string
    {
        return self::hotp($base32Secret, intdiv($timestamp, self::PERIOD));
    }

    /**
     * Verify a user-supplied code, allowing ±$window steps for clock drift.
     */
    public static function verify(string $base32Secret, string $code, int $timestamp, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $counter = intdiv($timestamp, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($base32Secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// provisioning URI for QR codes / manual entry. */
    public static function provisioningUri(string $base32Secret, string $account, string $issuer = 'Aircast Pro'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Generate N human-friendly recovery codes (plaintext) for one-time use.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    private static function hotp(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        $binCounter = pack('J', $counter); // 64-bit unsigned, big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $code = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $output;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }
        $binary = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }
        return $output;
    }
}
