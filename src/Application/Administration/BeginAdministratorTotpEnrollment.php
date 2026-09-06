<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorTotpAlgorithm;

final readonly class BeginAdministratorTotpEnrollment
{
    public function __construct(private AdministratorTotpAlgorithm $totpAlgorithm)
    {
    }

    public function begin(string $accountName): AdministratorTotpEnrollment
    {
        $secret = $this->totpAlgorithm->generateSecret();
        $completed = false;

        try {
            $enrollment = new AdministratorTotpEnrollment(
                $secret,
                $this->totpAlgorithm->provisioningUri($secret, $accountName),
            );
            $completed = true;

            return $enrollment;
        } finally {
            if (!$completed) {
                sodium_memzero($secret);
            }
        }
    }
}
