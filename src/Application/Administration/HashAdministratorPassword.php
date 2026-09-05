<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;

final readonly class HashAdministratorPassword
{
    public function __construct(
        private AdministratorPasswordPolicy $passwordPolicy,
        private AdministratorPasswordHasher $passwordHasher,
    ) {
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        $this->passwordPolicy->assertAcceptable($plainPassword);

        return $this->passwordHasher->hash($plainPassword);
    }
}
