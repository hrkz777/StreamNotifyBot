<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\IdGenerator;

final readonly class StorePlatformApiCredential
{
    public function __construct(private PlatformApiCredentialRepository $repository, private SecretCipher $secretCipher, private IdGenerator $idGenerator)
    {
    }

    public function store(PlatformApiCredentialConfiguration $configuration): string
    {
        $id = $this->idGenerator->generate();
        $plainValue = $configuration->toJson();
        try {
            $this->repository->save(new PlatformApiCredential(
                $id,
                $configuration->platform,
                $this->secretCipher->encrypt($plainValue, SecretPurpose::PlatformApiCredential, $id),
            ));
        } finally {
            sodium_memzero($plainValue);
        }

        return $id;
    }
}
