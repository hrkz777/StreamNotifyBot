<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpEnrollmentRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use UnexpectedValueException;

final readonly class ConfirmAdministratorTotpEnrollment
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private AdministratorTotpAlgorithm $totpAlgorithm,
        private AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        private SecretCipher $secretCipher,
        private AdministratorTotpEnrollmentRepository $enrollmentRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function confirm(
        string $administratorId,
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $confirmationCode,
    ): ?ConfirmedAdministratorTotpEnrollment {
        $plainRecoveryCodes = [];
        $confirmed = false;

        try {
            $enrolledAt = $this->clock->now();
            $matchedTimeStep = $this->totpAlgorithm->matchTimeStep($secret, $confirmationCode, $enrolledAt);
            if ($matchedTimeStep === null) {
                return null;
            }

            $plainRecoveryCodes = $this->recoveryCodeAlgorithm->generate();
            $recoveryCodes = $this->createRecoveryCodes(
                $administratorId,
                $plainRecoveryCodes,
                $enrolledAt,
            );
            $credential = new AdministratorTotpCredential(
                $administratorId,
                $this->secretCipher->encrypt(
                    $secret,
                    SecretPurpose::AdministratorTotpSecret,
                    $administratorId,
                ),
                $matchedTimeStep,
            );

            if (!$this->enrollmentRepository->confirm($credential, $recoveryCodes, $enrolledAt)) {
                return null;
            }

            $confirmed = true;

            return new ConfirmedAdministratorTotpEnrollment($plainRecoveryCodes);
        } finally {
            sodium_memzero($secret);

            if (!$confirmed) {
                self::eraseRecoveryCodes($plainRecoveryCodes);
            }
        }
    }

    /**
     * @param list<string> $plainRecoveryCodes
     * @return list<AdministratorRecoveryCode>
     */
    private function createRecoveryCodes(
        string $administratorId,
        array $plainRecoveryCodes,
        \DateTimeImmutable $createdAt,
    ): array {
        if (count($plainRecoveryCodes) !== self::RECOVERY_CODE_COUNT) {
            throw new UnexpectedValueException('回復コード生成結果は10件である必要があります。');
        }

        $recoveryCodes = [];
        $hashes = [];

        foreach ($plainRecoveryCodes as $plainRecoveryCode) {
            $codeHash = $this->recoveryCodeAlgorithm->hash($plainRecoveryCode);
            if (isset($hashes[$codeHash])) {
                throw new UnexpectedValueException('回復コード生成結果に重複があります。');
            }

            $hashes[$codeHash] = true;
            $recoveryCodes[] = new AdministratorRecoveryCode(
                $this->idGenerator->generate(),
                $administratorId,
                $codeHash,
                $createdAt,
                null,
            );
        }

        return $recoveryCodes;
    }

    /** @param list<string> $recoveryCodes */
    private static function eraseRecoveryCodes(array &$recoveryCodes): void
    {
        foreach ($recoveryCodes as &$recoveryCode) {
            sodium_memzero($recoveryCode);
        }

        unset($recoveryCode);
    }
}
