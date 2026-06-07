<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

/**
 * Faz H3-5 — Merkezi bcrypt yardımcısı.
 *
 * 4 farklı callsite bcrypt'i doğrudan `password_hash($x, PASSWORD_BCRYPT)`
 * ile çağırıyordu — cost = PHP varsayılan 10 (≈250ms 2024 donanımında).
 * Prod ortamı için cost 12 (≈1s) önerilir; .env üzerinden ayarlanabilir.
 *
 * Konfigürasyon (.env.production):
 *   BCRYPT_COST=12         # default
 *   BCRYPT_COST=14         # paranoyak (~4s per hash) — login UX'i etkiler
 *
 * Cost ne kadar yüksekse:
 *   + brute-force / dictionary saldırısı o kadar maliyetli
 *   - login & provision o kadar yavaş
 *
 * `needsRehash()` ile mevcut hash'ler login esnasında sessiz yükseltilir.
 */
final class PasswordHasher
{
    private const DEFAULT_COST = 12;
    private const MIN_COST = 10;
    private const MAX_COST = 15;

    public static function cost(): int
    {
        $env = getenv('BCRYPT_COST');
        if ($env === false || $env === '') {
            return self::DEFAULT_COST;
        }
        $cost = (int) $env;
        // PHP password_hash kabul aralığı [4,31]. Mantıklı tavanlar koy
        // (15 üzeri TR kullanıcı için login >8s; pratik DoS).
        if ($cost < self::MIN_COST) {
            return self::MIN_COST;
        }
        if ($cost > self::MAX_COST) {
            return self::MAX_COST;
        }
        return $cost;
    }

    public static function hash(string $plaintext): string
    {
        // PHP 8.0+ password_hash() dönüş tipi string (false yok).
        // PHPStan'a göre defensive check gereksiz — kaldırıldı (Faz CTO-15).
        return password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /**
     * Login akışı: kullanıcının mevcut hash'i halen geçerliyse ama cost
     * artmışsa, login başarısının ardından yeni hash üret ve depola.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }
}
