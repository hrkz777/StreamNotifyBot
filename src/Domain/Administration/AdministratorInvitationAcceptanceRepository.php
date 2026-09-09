<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorInvitationAcceptanceRepository
{
    public function findTargetAdministratorId(string $tokenHash, DateTimeImmutable $now): ?string;

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    public function accept(
        string $tokenHash,
        string $administratorId,
        string $passwordHash,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $acceptedAt,
    ): bool;
}
