<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Çok fazla istek.')
    {
        parent::__construct(429, $message);
    }
}
