<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\UserRepository;
use RuntimeException;

/**
 * Partner-radio credential provisioning.
 *
 * - Generates a deterministic-yet-collision-safe username from the station
 *   name + city (mesk_fm + Konya → meskfm_konya, meskfm_konya_2, ...)
 * - Generates a one-time strong password (PasswordPolicy::generate)
 * - Creates the user, binds it to the station, returns the plaintext password
 *   exactly once. The hash is persisted; plaintext is never stored.
 */
final class RadioCredentialService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly StationRepository $stationRepository
    ) {
    }

    /**
     * Provision a new partner-radio user for a station that doesn't have one
     * yet. Returns the username and the ONE-SHOT plaintext password.
     *
     * @return array{user:array<string,mixed>,username:string,password:string}
     */
    public function provision(string $stationId): array
    {
        $station = $this->stationRepository->findById($stationId);
        if ($station === null) {
            throw new RuntimeException('Radyo bulunamadı.');
        }
        if (!empty($station['user_id'])) {
            throw new RuntimeException('Bu radyo için zaten kullanıcı oluşturulmuş.');
        }

        $username = $this->generateUniqueUsername(
            (string) ($station['name'] ?? ''),
            (string) ($station['city_name'] ?? '')
        );
        $password = PasswordPolicy::generate();
        PasswordPolicy::assertStrong($password);

        $user = $this->userRepository->insert([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'real_name' => (string) ($station['name'] ?? $username),
            'roles' => [Rbac::ROLE_STATION_USER],
            'station_id' => $stationId,
        ]);
        $this->stationRepository->bindUser($stationId, (string) $user['id']);

        return [
            'user' => $user,
            'username' => $username,
            // ONE-SHOT — caller must show this to the admin immediately and
            // discard it. The hash is what's persisted; we never log/store
            // the plaintext.
            'password' => $password,
        ];
    }

    /**
     * Rotate the password for an existing partner user. Returns the new
     * plaintext (one-shot) so the admin can read it back to the partner.
     */
    public function rotatePassword(string $stationId): array
    {
        $station = $this->stationRepository->findById($stationId);
        if ($station === null || empty($station['user_id'])) {
            throw new RuntimeException('Radyonun kullanıcısı yok.');
        }
        $password = PasswordPolicy::generate();
        PasswordPolicy::assertStrong($password);
        $this->userRepository->updatePassword(
            (string) $station['user_id'],
            password_hash($password, PASSWORD_BCRYPT)
        );

        return ['password' => $password, 'user_id' => (string) $station['user_id']];
    }

    /**
     * Username pattern: <slugified name>_<city> with numeric suffixes for
     * collisions. Falls back to "_<n>" if the city is unknown.
     */
    public function generateUniqueUsername(string $name, string $city): string
    {
        $base = $this->slug($name);
        if ($base === '') {
            $base = 'radio';
        }
        $citySlug = $this->slug($city);
        $candidate = $citySlug === '' ? $base : "{$base}_{$citySlug}";

        if ($this->userRepository->findByUsername($candidate) === null) {
            return $candidate;
        }
        // …_2, …_3, … until free (hard cap defends against pathological input).
        for ($n = 2; $n <= 999; $n++) {
            $next = "{$candidate}_{$n}";
            if ($this->userRepository->findByUsername($next) === null) {
                return $next;
            }
        }
        throw new RuntimeException('Kullanıcı adı türetilemedi.');
    }

    /**
     * Loose ASCII slug that survives Turkish input — drops diacritics, keeps
     * only [a-z0-9], joins with no separator (kept compact for the prefix).
     */
    private function slug(string $value): string
    {
        $tr = ['ş' => 's', 'Ş' => 's', 'ı' => 'i', 'İ' => 'i', 'ç' => 'c', 'Ç' => 'c',
            'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o'];
        $value = strtr($value, $tr);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        return $value;
    }
}
