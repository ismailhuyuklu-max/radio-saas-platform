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

while (true) {
    $nextJob = $jobRepository->reserveNextJob('render_sponsor_bundle');

    if ($nextJob === null) {
        usleep(500000);
        continue;
    }

    $job->handle($nextJob);
}
