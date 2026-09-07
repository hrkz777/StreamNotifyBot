<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorSetupTokenGenerator
{
    public function generate(): string;
}
