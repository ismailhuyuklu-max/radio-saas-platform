<?php

declare(strict_types=1);

/**
 * Audit-log retention job. Deletes audit rows older than AUDIT_RETENTION_DAYS
 * (default 180). Schedule via cron, e.g.:
 *   0 3 * * *  php /var/www/backend/bin/prune-audit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use RadioSaaS\Infrastructure\PdoFactory;
use RadioSaaS\Repository\AuditLogRepository;

$days = (int) (getenv('AUDIT_RETENTION_DAYS') ?: 180);
$repo = new AuditLogRepository(PdoFactory::fromEnv());
$deleted = $repo->pruneOlderThan($days);

echo "Pruned {$deleted} audit rows older than {$days} days.\n";
