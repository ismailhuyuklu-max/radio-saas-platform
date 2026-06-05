<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Çakışma.')
    {
        parent::__construct(409, $message);
    }
}
