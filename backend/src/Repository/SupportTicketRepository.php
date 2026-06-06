<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;
use RuntimeException;

/**
 * support_tickets + support_ticket_messages.
 *
 * Tenant filtering is the caller's responsibility — pass stationId to scope
 * a partner-side query, omit it for the admin worklist. Repository never
 * returns cross-tenant data unsolicited.
 */
final class SupportTicketRepository
{
    public const CATEGORIES = ['technical', 'broadcast', 'ad', 'news', 'general'];
    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $stationId, string $category, string $subject, string $body, ?string $createdBy = null): array
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Geçersiz destek kategorisi.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets (station_id, category, subject, body, created_by)
             VALUES (:s, :c, :sub, :b, :u)
             RETURNING id'
        );
        $stmt->execute([
            's' => $stationId,
            'c' => $category,
            'sub' => mb_substr(trim($subject), 0, 255),
            'b' => trim($body),
            'u' => $createdBy,
        ]);
        return $this->findById((string) $stmt->fetchColumn()) ?? [];
    }

    public function findById(string $id, ?string $tenantStationId = null): ?array
    {
        $sql = 'SELECT t.*, s.name AS station_name, s.slug AS station_slug
                FROM support_tickets t
                INNER JOIN stations s ON s.id = t.station_id
                WHERE t.id = :id';
        $params = ['id' => $id];
        if ($tenantStationId !== null) {
            $sql .= ' AND t.station_id = :sid';
            $params['sid'] = $tenantStationId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{station_id?:string,status?:string,category?:string} $filters
     */
    public function listTickets(array $filters = [], int $limit = 100): array
    {
        $sql = 'SELECT t.*, s.name AS station_name, s.slug AS station_slug
                FROM support_tickets t
                INNER JOIN stations s ON s.id = t.station_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['station_id'])) {
            $sql .= ' AND t.station_id = :sid';
            $params['sid'] = $filters['station_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND t.status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND t.category = :cat';
            $params['cat'] = $filters['category'];
        }
        $sql .= ' ORDER BY t.created_at DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue('lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function updateStatus(string $id, string $status): ?array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Geçersiz durum.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET status = :s, updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['s' => $status, 'id' => $id]);
        return $this->findById($id);
    }

    public function addMessage(string $ticketId, string $authorType, ?string $authorId, string $body): array
    {
        if (!in_array($authorType, ['radio', 'admin'], true)) {
            throw new RuntimeException('Geçersiz yazar tipi.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, body)
             VALUES (:t, :at, :ai, :b)
             RETURNING id, ticket_id, author_type, author_id, body, created_at'
        );
        $stmt->execute([
            't' => $ticketId,
            'at' => $authorType,
            'ai' => $authorId,
            'b' => trim($body),
        ]);
        // Touch parent so listTickets sorts by activity.
        $this->pdo->prepare('UPDATE support_tickets SET updated_at = now() WHERE id = :t')
            ->execute(['t' => $ticketId]);
        return $stmt->fetch() ?: [];
    }

    public function listMessages(string $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_ticket_messages WHERE ticket_id = :t ORDER BY created_at ASC'
        );
        $stmt->execute(['t' => $ticketId]);
        return $stmt->fetchAll() ?: [];
    }
}
