<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorTotpCredentialRepository
{
    public function add(AdministratorTotpCredential $credential): void;

    public function findByAdministratorId(string $administratorId): ?AdministratorTotpCredential;

    /** @return list<AdministratorTotpCredential> */
    public function findAll(): array;

    /** @param list<AdministratorTotpCredential> $credentials */
    public function replaceEncryptedSecrets(array $credentials): void;

    public function acceptTimeStep(string $administratorId, int $timeStep): bool;
}
