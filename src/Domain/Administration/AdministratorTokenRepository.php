<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorTokenRepository
{
    public function add(AdministratorToken $token): void;

    public function consumeByHash(
        string $tokenHash,
        AdministratorTokenPurpose $purpose,
        DateTimeImmutable $consumedAt,
    ): ?AdministratorToken;
}
