<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptionKeyRing;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;

final readonly class ReencryptAdministratorTotpCredentials
{
    public function __construct(
        private AdministratorTotpCredentialRepository $credentialRepository,
        private EncryptionKeyRing $keyRing,
        private SecretCipher $secretCipher,
    ) {
    }

    public function reencrypt(): int
    {
        $currentKeyId = $this->keyRing->current()->id;
        $replacedCredentials = [];

        foreach ($this->credentialRepository->findAll() as $credential) {
            if ($credential->encryptedSecret->keyId === $currentKeyId) {
                continue;
            }

            $secret = $this->secretCipher->decrypt(
                $credential->encryptedSecret,
                SecretPurpose::AdministratorTotpSecret,
                $credential->administratorId,
            );
            try {
                $replacedCredentials[] = new AdministratorTotpCredential(
                    $credential->administratorId,
                    $this->secretCipher->encrypt(
                        $secret,
                        SecretPurpose::AdministratorTotpSecret,
                        $credential->administratorId,
                    ),
                    $credential->lastAcceptedTimeStep,
                );
            } finally {
                sodium_memzero($secret);
            }
        }

        if ($replacedCredentials === []) {
            return 0;
        }

        $this->credentialRepository->replaceEncryptedSecrets($replacedCredentials);

        return count($replacedCredentials);
    }
}
