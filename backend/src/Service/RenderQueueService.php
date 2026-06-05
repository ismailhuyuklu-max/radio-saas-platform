<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Repository\JobRepository;

final class RenderQueueService
{
    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function buildSponsorRenderPayload(
        string $mediaContentId,
        string $contentType,
        string $regionId,
        ?array $introSponsor = null,
        ?array $outroSponsor = null,
        array $context = []
    ): array {
        return array_filter([
            'media_content_id' => $mediaContentId,
            'content_type' => $contentType,
            'region_id' => $regionId,
            'intro_sponsor' => $introSponsor,
            'outro_sponsor' => $outroSponsor,
            'placement_chain' => ['intro', 'outro'],
            'render_mode' => 'jingle_concat',
            'context' => $context,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function queueSponsorRender(string $mediaContentId, array $payload = []): string
    {
        if (!isset($payload['render_mode'])) {
            $payload['render_mode'] = 'sponsor_bundle';
        }

        if (!isset($payload['placement_chain'])) {
            $payload['placement_chain'] = ['intro', 'outro'];
        }

        return $this->jobs->enqueue('render_sponsor_bundle', $mediaContentId, $payload);
    }
}
