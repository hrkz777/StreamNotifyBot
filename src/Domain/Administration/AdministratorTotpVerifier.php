<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorTotpVerifier
{
    public function verify(string $administratorId, #[\SensitiveParameter] string $code): bool;
}
