<?php

declare(strict_types=1);

namespace RadioSaaS\Exception;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Kayıt bulunamadı.')
    {
        parent::__construct(404, $message);
    }
}
