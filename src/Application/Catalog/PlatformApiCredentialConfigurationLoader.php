<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;

interface PlatformApiCredentialConfigurationLoader
{
    public function load(Platform $platform): ?PlatformApiCredentialConfiguration;
}
