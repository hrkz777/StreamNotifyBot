<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class AdministratorTotpEnrollment
{
    public function __construct(
        #[\SensitiveParameter] public string $secret,
        public string $provisioningUri,
    ) {
    }
}
