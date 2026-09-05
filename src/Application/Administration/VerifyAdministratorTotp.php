<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;

final readonly class VerifyAdministratorTotp
{
    public function __construct(
        private AdministratorTotpCredentialRepository $credentialRepository,
        private SecretCipher $secretCipher,
        private AdministratorTotpAlgorithm $totpAlgorithm,
        private Clock $clock,
    ) {
    }

    public function verify(string $administratorId, #[\SensitiveParameter] string $code): bool
    {
        $credential = $this->credentialRepository->findByAdministratorId($administratorId);
        if ($credential === null) {
            return false;
        }

        $secret = $this->secretCipher->decrypt(
            $credential->encryptedSecret,
            SecretPurpose::AdministratorTotpSecret,
            $administratorId,
        );

        try {
            $timeStep = $this->totpAlgorithm->matchTimeStep($secret, $code, $this->clock->now());

            return $timeStep !== null
                && $this->credentialRepository->acceptTimeStep($administratorId, $timeStep);
        } finally {
            sodium_memzero($secret);
        }
    }
}
