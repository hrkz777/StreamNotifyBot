<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface InitialOwnerRepository
{
    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    public function create(
        Administrator $owner,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $completedAt,
    ): void;
}
