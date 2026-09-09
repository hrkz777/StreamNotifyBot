<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorDeletionRepository
{
    public function delete(string $administratorId, DateTimeImmutable $deletedAt): bool;
}
