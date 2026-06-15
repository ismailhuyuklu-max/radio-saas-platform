<?php

declare(strict_types=1);

namespace RadioSaaS\Repository;

use PDO;
use RuntimeException;

/**
 * publisher_support_tickets — yayinci (sync client) destek talepleri.
 * Mevcut portal support_tickets'tan bagimsizdir; yayinci iletisim + radyo
 * bilgileri (snapshot) + admin notu tutar.
 */
final class PublisherSupportRepository
{
    public const STATUSES = ['new', 'in_progress', 'resolved', 'closed'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Talep olustur. Radyo bilgileri (radio_name/frequency/region/city) sunucuda
     * dogrulanmis station kaydindan gelmelidir — istemci verisine guvenme.
     *
     * @param array{station_id:string,radio_name:?string,frequency:?string,region:?string,city:?string,full_name:string,phone:string,email:string,message:string} $data
     */
    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publisher_support_tickets
                (station_id, radio_name, frequency, region, city, full_name, phone, email, message, status)
             VALUES
                (:station_id, :radio_name, :frequency, :region, :city, :full_name, :phone, :email, :message, :status)
             RETURNING id'
        );
        $stmt->execute([
            'station_id' => $data['station_id'],
            'radio_name' => $data['radio_name'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'region' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
            'full_name' => mb_substr(trim($data['full_name']), 0, 160),
            'phone' => mb_substr(trim($data['phone']), 0, 64),
            'email' => mb_substr(trim($data['email']), 0, 160),
            'message' => trim($data['message']),
            'status' => 'new',
        ]);

        return $this->findById((string) $stmt->fetchColumn()) ?? [];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM publisher_support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Admin liste — duruma gore filtreli, en yeni ustte.
     *
     * @param array{status?:string,keyword?:string} $filters
     */
    public function listTickets(array $filters = [], int $limit = 200): array
    {
        $sql = 'SELECT * FROM publisher_support_tickets WHERE 1=1';
        $params = [];

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $sql .= ' AND status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $sql .= ' AND (radio_name ILIKE :kw OR full_name ILIKE :kw OR email ILIKE :kw OR phone ILIKE :kw)';
            $params['kw'] = '%' . $filters['keyword'] . '%';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue('lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Durum ve/veya admin notu guncelle. Bos status gonderilirse degistirilmez.
     */
    public function update(string $id, ?string $status, ?string $adminNote): ?array
    {
        $sets = [];
        $params = ['id' => $id];

        if ($status !== null && $status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Gecersiz durum degeri.');
            }
            $sets[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($adminNote !== null) {
            $sets[] = 'admin_note = :note';
            $params['note'] = trim($adminNote) === '' ? null : trim($adminNote);
        }
        if ($sets === []) {
            return $this->findById($id);
        }

        $sets[] = 'updated_at = now()';
        $sql = 'UPDATE publisher_support_tickets SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    /**
     * Rate limit — bir istasyonun son N dakikada actigi talep sayisi.
     */
    public function countRecentByStation(string $stationId, int $minutes): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM publisher_support_tickets
             WHERE station_id = :sid AND created_at > now() - (:mins || ' minutes')::interval"
        );
        $stmt->execute(['sid' => $stationId, 'mins' => (string) $minutes]);

        return (int) $stmt->fetchColumn();
    }
}
