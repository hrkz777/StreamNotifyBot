<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AuthenticationAttemptRepository
{
    public function record(AuthenticationAttempt $attempt): void;

    public function countFailuresSince(string $loginIdentifierHash, string $sourceIp, DateTimeImmutable $since): int;
}
