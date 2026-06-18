<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\StationRepository;
use RadioSaaS\Repository\UserRepository;
use RuntimeException;

/**
 * Partner-radio credential provisioning.
 *
 * - Uses the station name as the username, with collision-safe suffixes
 *   only when another user already has the same station name.
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

        $username = $this->generateUniqueUsername((string) ($station['name'] ?? ''));
        $password = PasswordPolicy::generate();
        PasswordPolicy::assertStrong($password);

        $user = $this->userRepository->insert([
            'username' => $username,
            'password_hash' => PasswordHasher::hash($password), // Faz H3-5
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
            PasswordHasher::hash($password) // Faz H3-5
        );

        return ['password' => $password, 'user_id' => (string) $station['user_id']];
    }

    /**
     * Username pattern: station name as entered. Numeric suffixes are used
     * only for collisions, so "Akdeniz FM" becomes "Akdeniz FM 2" if needed.
     */
    public function generateUniqueUsername(string $name): string
    {
        $base = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($base === '') {
            $base = 'Radyo';
        }
        $candidate = $base;

        if ($this->userRepository->findByUsername($candidate) === null) {
            return $candidate;
        }
        // " 2", " 3", ... until free (hard cap defends against pathological input).
        for ($n = 2; $n <= 999; $n++) {
            $next = "{$base} {$n}";
            if ($this->userRepository->findByUsername($next) === null) {
                return $next;
            }
        }
        throw new RuntimeException('Kullanıcı adı türetilemedi.');
    }
}
