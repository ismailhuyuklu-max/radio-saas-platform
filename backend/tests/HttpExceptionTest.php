<?php

declare(strict_types=1);

require __DIR__ . '/../src/Exception/HttpException.php';
foreach (['ValidationException', 'UnauthorizedException', 'ForbiddenException', 'NotFoundException', 'ConflictException', 'TooManyRequestsException'] as $cls) {
    require __DIR__ . '/../src/Exception/' . $cls . '.php';
}

use RadioSaaS\Exception\ConflictException;
use RadioSaaS\Exception\ForbiddenException;
use RadioSaaS\Exception\HttpException;
use RadioSaaS\Exception\NotFoundException;
use RadioSaaS\Exception\TooManyRequestsException;
use RadioSaaS\Exception\UnauthorizedException;
use RadioSaaS\Exception\ValidationException;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) { $passed++; } else { $failed++; fwrite(STDERR, "  FAIL: {$m}\n"); }
}

check((new ValidationException())->getStatusCode() === 400, 'validation = 400');
check((new UnauthorizedException())->getStatusCode() === 401, 'unauthorized = 401');
check((new ForbiddenException())->getStatusCode() === 403, 'forbidden = 403');
check((new NotFoundException())->getStatusCode() === 404, 'not found = 404');
check((new ConflictException())->getStatusCode() === 409, 'conflict = 409');
check((new TooManyRequestsException())->getStatusCode() === 429, 'too many = 429');

// custom message preserved + is a Throwable/HttpException
$e = new ForbiddenException('özel mesaj');
check($e->getMessage() === 'özel mesaj', 'custom message preserved');
check($e instanceof HttpException, 'subclass of HttpException');
check($e instanceof \RuntimeException, 'is a RuntimeException (backward compatible)');

echo "HttpException tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
