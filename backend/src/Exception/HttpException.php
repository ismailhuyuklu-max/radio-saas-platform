<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

use RuntimeException;

/**
 * Base for exceptions that map to an explicit HTTP status, so the front
 * controller no longer has to guess the status from the message text.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
