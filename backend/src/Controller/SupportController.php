<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Exception\ForbiddenException;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Repository\SupportTicketRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RuntimeException;

/**
 * Support tickets API — single controller serves both:
 *  - partner side (station_user, scoped to own station_id, support:open)
 *  - admin side (manager/super, full worklist, support:manage)
 *
 * Tenant guarantee: a station_user can never see, comment on, or modify a
 * ticket belonging to another station. The controller compares the ticket's
 * station_id against the caller's bound station_id on every access.
 */
final class SupportController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly SupportTicketRepository $tickets,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    /** GET /portal/support — partner lists own tickets. */
    public function partnerIndex(): void
    {
        $user = $this->guardPortal();
        $list = $this->tickets->listTickets([
            'station_id' => (string) $user['station_id'],
        ], 100);
        $this->respond(['code' => 0, 'result' => ['tickets' => $list]]);
    }

    /** POST /portal/support — partner opens a ticket. */
    public function partnerCreate(): void
    {
        $user = $this->guardPortal();
        $payload = $this->readJsonPayload();
        try {
            $ticket = $this->tickets->create(
                (string) $user['station_id'],
                (string) ($payload['category'] ?? 'general'),
                (string) ($payload['subject'] ?? ''),
                (string) ($payload['body'] ?? ''),
                (string) ($user['id'] ?? '') ?: null
            );
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'partner'),
            'support_open',
            'station',
            (string) $user['station_id'],
            ['ticket_id' => (string) ($ticket['id'] ?? ''), 'category' => $ticket['category'] ?? '']
        );
        $this->respond(['code' => 0, 'result' => $ticket], 201);
    }

    /** GET /portal/support/{id} — partner reads own ticket + messages. */
    public function partnerShow(string $id): void
    {
        $user = $this->guardPortal();
        $ticket = $this->tickets->findById($id, (string) $user['station_id']);
        if ($ticket === null) {
            $this->respond(['code' => 1, 'message' => 'Talep bulunamadı.'], 404);
            return;
        }
        $messages = $this->tickets->listMessages($id);
        $this->respond(['code' => 0, 'result' => ['ticket' => $ticket, 'messages' => $messages]]);
    }

    /** POST /portal/support/{id}/message — partner replies. */
    public function partnerReply(string $id): void
    {
        $user = $this->guardPortal();
        // Tenant check: ticket must belong to the partner's station.
        $ticket = $this->tickets->findById($id, (string) $user['station_id']);
        if ($ticket === null) {
            throw new ForbiddenException('Bu talebe erişiminiz yok.');
        }
        $body = trim((string) ($this->readJsonPayload()['body'] ?? ''));
        if ($body === '') {
            $this->respond(['code' => 1, 'message' => 'Mesaj boş olamaz.'], 400);
            return;
        }
        $msg = $this->tickets->addMessage($id, 'radio', (string) ($user['id'] ?? ''), $body);
        $this->respond(['code' => 0, 'result' => $msg], 201);
    }

    // ---- Admin side -------------------------------------------------------

    public function adminIndex(): void
    {
        $this->authenticator->authorize($this->extractToken(), 'support:manage');
        $list = $this->tickets->listTickets([
            'status' => $_GET['status'] ?? null,
            'category' => $_GET['category'] ?? null,
        ], 200);
        $this->respond(['code' => 0, 'result' => ['tickets' => $list]]);
    }

    public function adminShow(string $id): void
    {
        $this->authenticator->authorize($this->extractToken(), 'support:manage');
        $ticket = $this->tickets->findById($id);
        if ($ticket === null) {
            $this->respond(['code' => 1, 'message' => 'Talep bulunamadı.'], 404);
            return;
        }
        $messages = $this->tickets->listMessages($id);
        $this->respond(['code' => 0, 'result' => ['ticket' => $ticket, 'messages' => $messages]]);
    }

    public function adminUpdateStatus(string $id): void
    {
        $user = $this->authenticator->authorize($this->extractToken(), 'support:manage');
        $status = (string) ($this->readJsonPayload()['status'] ?? '');
        try {
            $ticket = $this->tickets->updateStatus($id, $status);
        } catch (RuntimeException $e) {
            $this->respond(['code' => 1, 'message' => $e->getMessage()], 400);
            return;
        }
        $this->auditLogRepository->log(
            (string) ($user['username'] ?? 'admin'),
            'support_status',
            'station',
            (string) ($ticket['station_id'] ?? ''),
            ['ticket_id' => $id, 'status' => $status]
        );
        $this->respond(['code' => 0, 'result' => $ticket]);
    }

    public function adminReply(string $id): void
    {
        $user = $this->authenticator->authorize($this->extractToken(), 'support:manage');
        $body = trim((string) ($this->readJsonPayload()['body'] ?? ''));
        if ($body === '') {
            $this->respond(['code' => 1, 'message' => 'Mesaj boş olamaz.'], 400);
            return;
        }
        $msg = $this->tickets->addMessage($id, 'admin', (string) ($user['id'] ?? ''), $body);
        $this->respond(['code' => 0, 'result' => $msg], 201);
    }

    // ---- internals --------------------------------------------------------

    /**
     * Resolve the calling partner. A station_user MUST have a station_id; any
     * other role calling /portal/support/* is rejected (admins use the admin
     * endpoints below). Returns the user row.
     */
    private function guardPortal(): array
    {
        $user = $this->authenticator->authorize($this->extractToken(), 'support:open');
        $roles = (array) ($user['roles'] ?? []);
        if (!in_array('station_user', $roles, true)) {
            throw new ForbiddenException('Bu uç noktayı yalnızca partner radyo kullanıcısı çağırabilir.');
        }
        if (empty($user['station_id'])) {
            throw new ForbiddenException('Hesap herhangi bir radyoya bağlı değil.');
        }
        return $user;
    }

    private function extractToken(): ?string
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if (is_string($token) && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            return trim($matches[1]);
        }
        return is_string($token) ? $token : null;
    }

    private function readJsonPayload(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
