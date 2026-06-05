<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Bu işlem için yetkiniz yok.')
    {
        parent::__construct(403, $message);
    }
}
