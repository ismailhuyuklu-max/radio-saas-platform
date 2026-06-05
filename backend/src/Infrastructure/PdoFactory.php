<?php

declare(strict_types=1);

namespace RadioSaaS\Infrastructure;

use PDO;
use PDOException;

final class PdoFactory
{
    public static function fromEnv(): PDO
    {
        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'radio_saas';
        $user = getenv('DB_USER') ?: 'radio_saas';
        $password = getenv('DB_PASSWORD') ?: 'radio_saas_password';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new PDOException('Database connection failed: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $pdo;
    }
}
