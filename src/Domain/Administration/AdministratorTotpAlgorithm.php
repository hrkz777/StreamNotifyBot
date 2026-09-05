<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorTotpAlgorithm
{
    public function generateSecret(): string;

    public function provisioningUri(#[\SensitiveParameter] string $secret, string $accountName): string;

    public function matchTimeStep(
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $code,
        DateTimeImmutable $now,
    ): ?int;
}
