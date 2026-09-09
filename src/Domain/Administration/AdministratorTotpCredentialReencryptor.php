<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorTotpCredentialReencryptor
{
    public function reencrypt(): int;
}
