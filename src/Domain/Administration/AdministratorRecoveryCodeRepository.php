<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorRecoveryCodeRepository
{
    /** @param list<AdministratorRecoveryCode> $codes */
    public function replaceForAdministrator(string $administratorId, array $codes): void;

    public function consumeByHash(string $administratorId, string $codeHash, DateTimeImmutable $usedAt): bool;
}
