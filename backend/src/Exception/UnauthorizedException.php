<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Yetkilendirme gerekli.')
    {
        parent::__construct(401, $message);
    }
}
