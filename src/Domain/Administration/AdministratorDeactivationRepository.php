<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorDeactivationRepository
{
    public function deactivate(string $administratorId, DateTimeImmutable $deactivatedAt): bool;
}
