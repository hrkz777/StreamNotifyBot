<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\AdministratorSetupTokenGenerator;

final readonly class CryptographicAdministratorSetupTokenGenerator implements AdministratorSetupTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
