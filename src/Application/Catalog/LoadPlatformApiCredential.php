<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;

final readonly class LoadPlatformApiCredential implements PlatformApiCredentialConfigurationLoader
{
    public function __construct(private PlatformApiCredentialRepository $repository, private SecretCipher $secretCipher)
    {
    }

    public function load(Platform $platform): ?PlatformApiCredentialConfiguration
    {
        $credential = $this->repository->findByPlatform($platform);
        if ($credential === null) {
            return null;
        }
        $plainValue = $this->secretCipher->decrypt($credential->encryptedValue, SecretPurpose::PlatformApiCredential, $credential->id);
        try {
            return PlatformApiCredentialConfiguration::fromJson($platform, $plainValue);
        } finally {
            sodium_memzero($plainValue);
        }
    }
}
