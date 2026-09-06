<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorTotpEnrollmentRepository
{
    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    public function confirm(
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $enrolledAt,
    ): bool;
}
