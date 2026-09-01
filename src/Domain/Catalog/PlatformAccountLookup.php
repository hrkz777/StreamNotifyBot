<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface PlatformAccountLookup
{
    public function resolve(Platform $platform, string $registrationIdentifier): ResolvedPlatformAccount;
}
