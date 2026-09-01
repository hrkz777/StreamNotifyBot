<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\System\DatabaseConnectionProbe;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class DoctrineDatabaseConnectionProbe implements DatabaseConnectionProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function isAvailable(): bool
    {
        try {
            $result = $this->connection->fetchOne('SELECT 1');

            return $result === 1 || $result === '1';
        } catch (Throwable) {
            return false;
        }
    }
}
