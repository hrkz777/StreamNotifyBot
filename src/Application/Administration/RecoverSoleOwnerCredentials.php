<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use UnexpectedValueException;

final readonly class RecoverSoleOwnerCredentials
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private HashAdministratorPassword $hashAdministratorPassword,
        private AdministratorTotpAlgorithm $totpAlgorithm,
        private AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        private SecretCipher $secretCipher,
        private SoleOwnerCredentialRecoveryRepository $recoveryRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function findTarget(): SoleOwnerRecoveryTarget
    {
        return $this->recoveryRepository->findTarget();
    }

    public function recover(
        SoleOwnerRecoveryTarget $target,
        #[\SensitiveParameter] string $plainPassword,
        #[\SensitiveParameter] string $totpSecret,
        #[\SensitiveParameter] string $confirmationCode,
    ): ?RecoveredSoleOwnerCredentials {
        $plainRecoveryCodes = [];
        $recovered = false;

        try {
            $recoveredAt = $this->clock->now();
            $matchedTimeStep = $this->totpAlgorithm->matchTimeStep($totpSecret, $confirmationCode, $recoveredAt);
            if ($matchedTimeStep === null) {
                return null;
            }

            $passwordHash = $this->hashAdministratorPassword->hash($plainPassword);
            $plainRecoveryCodes = $this->recoveryCodeAlgorithm->generate();
            $recoveryCodes = $this->createRecoveryCodes(
                $target->administratorId,
                $plainRecoveryCodes,
                $recoveredAt,
            );
            $credential = new AdministratorTotpCredential(
                $target->administratorId,
                $this->secretCipher->encrypt(
                    $totpSecret,
                    SecretPurpose::AdministratorTotpSecret,
                    $target->administratorId,
                ),
                $matchedTimeStep,
            );

            $this->recoveryRepository->recover(
                $target,
                $passwordHash,
                $credential,
                $recoveryCodes,
                $recoveredAt,
            );
            $result = new RecoveredSoleOwnerCredentials($target->administratorId, $plainRecoveryCodes);
            $recovered = true;

            return $result;
        } finally {
            sodium_memzero($plainPassword);
            sodium_memzero($totpSecret);
            sodium_memzero($confirmationCode);

            if (!$recovered) {
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
        DateTimeImmutable $createdAt,
    ): array {
        if (count($plainRecoveryCodes) !== self::RECOVERY_CODE_COUNT) {
            throw new UnexpectedValueException('回復コード生成結果は10件である必要があります。');
        }

        $recoveryCodes = [];
        $hashes = [];
        $ids = [];
        foreach ($plainRecoveryCodes as $plainRecoveryCode) {
            $codeHash = $this->recoveryCodeAlgorithm->hash($plainRecoveryCode);
            if (isset($hashes[$codeHash])) {
                throw new UnexpectedValueException('回復コード生成結果に重複があります。');
            }

            $recoveryCodeId = $this->idGenerator->generate();
            if (isset($ids[$recoveryCodeId])) {
                throw new UnexpectedValueException('回復コードID生成結果に重複があります。');
            }

            $hashes[$codeHash] = true;
            $ids[$recoveryCodeId] = true;
            $recoveryCodes[] = new AdministratorRecoveryCode(
                $recoveryCodeId,
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
