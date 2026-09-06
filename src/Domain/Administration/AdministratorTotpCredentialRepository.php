<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorTotpCredentialRepository
{
    public function add(AdministratorTotpCredential $credential): void;

    public function findByAdministratorId(string $administratorId): ?AdministratorTotpCredential;

    public function acceptTimeStep(string $administratorId, int $timeStep): bool;
}
