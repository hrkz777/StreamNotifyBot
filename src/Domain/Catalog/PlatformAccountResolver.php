<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface PlatformAccountResolver
{
    public function platform(): Platform;

    public function resolve(string $registrationIdentifier): ResolvedPlatformAccount;
}
