<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface PlatformApiCredentialRepository
{
    public function findByPlatform(Platform $platform): ?PlatformApiCredential;

    public function save(PlatformApiCredential $credential): void;
}
