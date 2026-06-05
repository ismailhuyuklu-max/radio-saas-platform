<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\SponsorAdRepository;
use RadioSaaS\Repository\StationRepository;
use RuntimeException;

final class MediaFeedService
{
    public function __construct(
        private readonly StationRepository $stationRepository,
        private readonly MediaContentRepository $mediaRepository,
        private readonly SponsorAdRepository $sponsorRepository,
        private readonly MinioStorage $storage
    ) {
    }

    public function resolve(string $stationSlug, string $partCode): array
    {
        $localFeedMode = filter_var(getenv('FEED_LOCAL_MODE') ?: (getenv('APP_ENV') === 'local' ? '1' : '0'), FILTER_VALIDATE_BOOL);
        $station = $this->stationRepository->findBySlug($stationSlug);

        if ($station === null) {
            throw new RuntimeException('Station not found.');
        }

        // Slot-aware: serve the audio bound to the broadcast slot on air now
        // (e.g. the 18:00 bulletin between 18:00–20:00), falling back to the
        // latest renderable when no slot-specific media exists.
        $currentSlot = \RadioSaaS\Service\BroadcastSlot::current(time());
        $media = $this->mediaRepository->findRenderableForSlot(
            (string) $station['region_id'],
            $partCode,
            $currentSlot
        );

        if ($media === null) {
            throw new RuntimeException('No active media content found for this region and part.');
        }

        $sponsor = $this->sponsorRepository->findActiveForRegionAndPart((string) $station['region_id'], $partCode);
        $resolvedBucket = $media['rendered_bucket'] ?: $media['source_bucket'];
        $resolvedKey = $media['rendered_key'] ?: $media['source_key'];
        $downloadUrl = $localFeedMode
            ? $this->storage->publicObjectUrl((string) getenv('MINIO_BUCKET_PUBLIC') ?: 'radio-media', $resolvedKey)
            : $this->storage->presignGetObject($resolvedBucket, $resolvedKey, 900);

        return [
            'station' => $station,
            'media' => $media,
            'sponsor' => $sponsor,
            'stream' => [
                'bucket' => $resolvedBucket,
                'key' => $resolvedKey,
                'mime' => $media['source_mime'],
                'download_url' => $downloadUrl,
                'public_url' => $localFeedMode
                    ? $downloadUrl
                    : $this->storage->presignGetObject($resolvedBucket, $resolvedKey, 900),
            ],
        ];
    }

    public function formatJson(array $bundle): string
    {
        return json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function formatXml(array $bundle): string
    {
        $xml = new \SimpleXMLElement('<feed/>');
        $xml->addChild('station', htmlspecialchars((string) $bundle['station']['name']));
        $xml->addChild('region', htmlspecialchars((string) $bundle['station']['region_name']));
        $xml->addChild('part', htmlspecialchars((string) $bundle['media']['part_code']));
        $xml->addChild('downloadUrl', htmlspecialchars((string) $bundle['stream']['download_url']));
        $xml->addChild('mime', htmlspecialchars((string) $bundle['stream']['mime']));

        return $xml->asXML() ?: '';
    }

    public function formatM3u(array $bundle): string
    {
        $stationName = (string) $bundle['station']['name'];
        $url = (string) $bundle['stream']['download_url'];

        return "#EXTM3U\n#EXTINF:-1,{$stationName}\n{$url}\n";
    }
}
