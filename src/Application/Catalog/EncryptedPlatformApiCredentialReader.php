<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountIntegrationNotConfigured;
use App\Domain\Catalog\PlatformApiCredentialReader;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use JsonException;

final readonly class EncryptedPlatformApiCredentialReader implements PlatformApiCredentialReader
{
    public function __construct(private PlatformApiCredentialRepository $repository, private SecretCipher $secretCipher)
    {
    }

    public function read(Platform $platform): array
    {
        $credential = $this->repository->findByPlatform($platform);
        if ($credential === null) {
            throw new PlatformAccountIntegrationNotConfigured($platform);
        }
        $plainValue = $this->secretCipher->decrypt($credential->encryptedSecret, SecretPurpose::PlatformApiCredential, $credential->id);
        try {
            $decoded = json_decode($plainValue, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_any($decoded, static fn (mixed $value): bool => !is_string($value))) {
                throw new PlatformAccountIntegrationNotConfigured($platform);
            }

            /** @var array<string, string> $decoded */
            return $decoded;
        } catch (JsonException) {
            throw new PlatformAccountIntegrationNotConfigured($platform);
        } finally {
            sodium_memzero($plainValue);
        }
    }
}
