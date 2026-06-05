<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;
use RadioSaaS\Service\LoginThrottle;

final class LoginThrottleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{fail_count:int, locked_until:?string} */
    public function status(string $username): array
    {
        $stmt = $this->pdo->prepare('SELECT fail_count, locked_until FROM login_throttle WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['fail_count' => 0, 'locked_until' => null];
        }
        return [
            'fail_count' => (int) $row['fail_count'],
            'locked_until' => $row['locked_until'] !== null ? (string) $row['locked_until'] : null,
        ];
    }

    /**
     * Record a failed attempt; locks the account once the threshold is hit.
     */
    public function registerFailure(string $username): void
    {
        $current = $this->status($username)['fail_count'];
        $next = $current + 1;

        if (LoginThrottle::shouldLock($next)) {
            // Lock and reset the counter so a fresh window starts after the lock.
            $this->pdo->prepare(
                "INSERT INTO login_throttle (username, fail_count, locked_until, updated_at)
                 VALUES (:u, 0, now() + (:mins || ' minutes')::interval, now())
                 ON CONFLICT (username) DO UPDATE
                 SET fail_count = 0,
                     locked_until = now() + (:mins || ' minutes')::interval,
                     updated_at = now()"
            )->execute(['u' => $username, 'mins' => LoginThrottle::LOCK_MINUTES]);
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO login_throttle (username, fail_count, updated_at)
             VALUES (:u, :c, now())
             ON CONFLICT (username) DO UPDATE
             SET fail_count = :c, updated_at = now()'
        )->execute(['u' => $username, 'c' => $next]);
    }

    public function reset(string $username): void
    {
        $this->pdo->prepare(
            "INSERT INTO login_throttle (username, fail_count, locked_until, updated_at)
             VALUES (:u, 0, NULL, now())
             ON CONFLICT (username) DO UPDATE
             SET fail_count = 0, locked_until = NULL, updated_at = now()"
        )->execute(['u' => $username]);
    }
}
