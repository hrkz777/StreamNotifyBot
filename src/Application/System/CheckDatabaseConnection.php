<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Domain\System\DatabaseConnectionProbe;

final readonly class CheckDatabaseConnection
{
    public function __construct(private DatabaseConnectionProbe $databaseConnectionProbe)
    {
    }

    public function isHealthy(): bool
    {
        return $this->databaseConnectionProbe->isAvailable();
    }
}
