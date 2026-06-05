<?php

declare(strict_types=1);

namespace RadioSaaS\Controller;

use RadioSaaS\Repository\AdCampaignRepository;
use RadioSaaS\Repository\AuditLogRepository;
use RadioSaaS\Service\AdminAuthenticator;
use RadioSaaS\Service\RevenueService;
use RuntimeException;

final class AdTrafficController
{
    public function __construct(
        private readonly AdminAuthenticator $authenticator,
        private readonly AdCampaignRepository $campaignRepository,
        private readonly AuditLogRepository $auditLogRepository
    ) {
    }

    public function index(): void
    {
        $this->guard('ad:view');
        $today = date('Y-m-d');
        $campaigns = $this->campaignRepository->listAll();

        $enriched = array_map(static function (array $campaign) use ($today): array {
            $campaign['metrics'] = RevenueService::computeCampaign($campaign, $today);
            return $campaign;
        }, $campaigns);

        $this->respond([
            'campaigns' => $enriched,
            'summary' => RevenueService::summary($campaigns, $today),
            'region_reach' => RevenueService::REGION_REACH,
        ]);
    }

    public function store(): void
    {
        $this->guard('ad:write');
        $payload = $this->readJsonPayload();
        if (trim((string) ($payload['advertiser_name'] ?? '')) === '') {
            throw new RuntimeException('Advertiser name is required.');
        }
        $campaign = $this->campaignRepository->insert($payload);
        $this->auditLogRepository->log('admin', 'create', 'ad_campaign', (string) ($campaign['id'] ?? ''), [
            'advertiser' => $campaign['advertiser_name'] ?? '',
            'pricing_model' => $campaign['pricing_model'] ?? '',
        ]);

        $this->respond(['code' => 0, 'result' => $campaign, 'message' => 'Success'], 201);
    }

    public function update(string $id): void
    {
        $this->guard('ad:write');
        $existing = $this->campaignRepository->findById($id);
        if ($existing === null) {
            throw new RuntimeException('Campaign not found.');
        }
        $payload = array_merge($existing, $this->readJsonPayload());
        $campaign = $this->campaignRepository->update($id, $payload);
        $this->auditLogRepository->log('admin', 'update', 'ad_campaign', $id, [
            'advertiser' => $campaign['advertiser_name'] ?? '',
        ]);

        $this->respond(['code' => 0, 'result' => $campaign, 'message' => 'Success']);
    }

    public function destroy(string $id): void
    {
        $this->guard('ad:write');
        $deleted = $this->campaignRepository->delete($id);
        if (!$deleted) {
            throw new RuntimeException('Campaign not found.');
        }
        $this->auditLogRepository->log('admin', 'delete', 'ad_campaign', $id, []);

        $this->respond(['code' => 0, 'result' => ['deleted' => true, 'campaign_id' => $id], 'message' => 'Success']);
    }

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function guard(string $permission): void
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if ($token !== null && preg_match('/Bearer\s+(.*)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }

        $this->authenticator->authorize($token, $permission);
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
}
