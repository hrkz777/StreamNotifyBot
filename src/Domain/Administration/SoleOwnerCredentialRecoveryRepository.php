<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface SoleOwnerCredentialRecoveryRepository
{
    public function findTarget(): SoleOwnerRecoveryTarget;

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    public function recover(
        SoleOwnerRecoveryTarget $expectedTarget,
        #[\SensitiveParameter] string $passwordHash,
        #[\SensitiveParameter] AdministratorTotpCredential $credential,
        #[\SensitiveParameter] array $recoveryCodes,
        DateTimeImmutable $recoveredAt,
    ): void;
}
