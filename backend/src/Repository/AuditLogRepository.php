<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        string $actorUsername,
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $payload = []
    ): void {
        $payload['correlation_id'] = $payload['correlation_id']
            ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? sprintf('corr-%s', bin2hex(random_bytes(8))));

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (actor_username, action, entity_type, entity_id, payload, ip_address, user_agent)
             VALUES
                (:actor_username, :action, :entity_type, :entity_id, CAST(:payload AS jsonb), :ip_address, :user_agent)'
        );
        $stmt->execute([
            'actor_username' => $actorUsername,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $this->clientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /**
     * Faz H3-4 — RequestContext::clientIp() TRUSTED_PROXY_IPS env'ini
     * dikkate alır; spoof'lanmış X-Forwarded-For artık güvenilmez.
     */
    private function clientIp(): ?string
    {
        return \RadioSaaS\Service\RequestContext::clientIp();
    }

    /**
     * Delete audit rows older than $days. Returns the number of rows removed.
     * Prevents unbounded audit_logs growth (run from a scheduled job).
     */
    public function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            "DELETE FROM audit_logs WHERE created_at < now() - (:days || ' days')::interval"
        );
        $stmt->execute(['days' => $days]);
        return $stmt->rowCount();
    }

    public function listLogs(array $filters = [], int $limit = 100): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM audit_logs
            WHERE 1 = 1
        SQL;
        $params = [];

        if (!empty($filters['actor_username'])) {
            $sql .= ' AND actor_username = :actor_username';
            $params['actor_username'] = $filters['actor_username'];
        }

        if (!empty($filters['action'])) {
            $sql .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['entity_id'])) {
            $sql .= ' AND entity_id = :entity_id';
            $params['entity_id'] = $filters['entity_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function exportCsv(array $filters = [], int $limit = 500): string
    {
        $rows = $this->listLogs($filters, $limit);
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['created_at', 'actor_username', 'action', 'entity_type', 'entity_id', 'correlation_id', 'payload']);
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? [];
            $correlationId = '';
            if (is_array($payload)) {
                $correlationId = (string) ($payload['correlation_id'] ?? '');
            }

            fputcsv($handle, [
                $row['created_at'] ?? '',
                $row['actor_username'] ?? '',
                $row['action'] ?? '',
                $row['entity_type'] ?? '',
                $row['entity_id'] ?? '',
                $correlationId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
