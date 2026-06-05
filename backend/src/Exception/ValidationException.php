<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class ValidationException extends HttpException
{
    public function __construct(string $message = 'Geçersiz istek.')
    {
        parent::__construct(400, $message);
    }
}
