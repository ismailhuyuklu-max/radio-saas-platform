<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(string $jobType, string $mediaContentId, array $payload = []): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media_jobs (job_type, media_content_id, payload, status, available_at)
             VALUES (:job_type, :media_content_id, CAST(:payload AS jsonb), \'pending\', now())
             RETURNING id'
        );
        $stmt->execute([
            'job_type' => $jobType,
            'media_content_id' => $mediaContentId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (string) $stmt->fetchColumn();
    }

    public function reserveNextJob(string $jobType): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT *
                 FROM media_jobs
                 WHERE job_type = :job_type
                   AND status = \'pending\'
                   AND available_at <= now()
                 ORDER BY created_at ASC
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1'
            );
            $stmt->execute(['job_type' => $jobType]);
            $job = $stmt->fetch();

            if ($job === false) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE media_jobs
                 SET status = \'processing\', attempts = attempts + 1, locked_at = now(), updated_at = now()
                 WHERE id = :id'
            );
            $update->execute(['id' => $job['id']]);
            $this->pdo->commit();

            return $job;
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    public function complete(string $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE media_jobs
             SET status = \'completed\', updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $jobId]);
    }

    public function fail(string $jobId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE media_jobs
             SET status = \'failed\', last_error = :error, updated_at = now()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'error' => $error,
        ]);
    }
}
