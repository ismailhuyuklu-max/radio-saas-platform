<?php

declare(strict_types=1);

use RadioSaaS\Infrastructure\MinioStorage;
use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Job\RenderSponsorJob;
use RadioSaaS\Repository\JobRepository;
use RadioSaaS\Repository\MediaContentRepository;
use RadioSaaS\Repository\SponsorAdRepository;

require __DIR__ . '/../vendor/autoload.php';

$pdo = PdoFactory::fromEnv();
$storage = MinioStorage::fromEnv();

$jobRepository = new JobRepository($pdo);
$mediaRepository = new MediaContentRepository($pdo);
$sponsorRepository = new SponsorAdRepository($pdo);
$job = new RenderSponsorJob($pdo, $storage, $mediaRepository, $sponsorRepository, $jobRepository);

// Faz CTO-15: SIGTERM/SIGINT ile düzgün kapanma — container restart sırasında
// yarıda kalan job'lar reserve'de kalmaz (üretim best practice). pcntl_signal
// olmayan ortamlarda (Windows native PHP) sessizce skip.
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        echo "[worker] SIGTERM received, finishing current job and exiting\n";
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        echo "[worker] SIGINT received, finishing current job and exiting\n";
        $running = false;
    });
}

while ($running) {
    $nextJob = $jobRepository->reserveNextJob('render_sponsor_bundle');

    if ($nextJob === null) {
        usleep(500000);
        continue;
    }

    $job->handle($nextJob);
}
