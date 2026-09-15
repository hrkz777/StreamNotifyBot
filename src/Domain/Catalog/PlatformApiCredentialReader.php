<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface PlatformApiCredentialReader
{
    /** @return array<string, string> */
    public function read(Platform $platform): array;
}
