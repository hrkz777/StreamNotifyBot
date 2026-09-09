<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\InitialOwnerRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use UnexpectedValueException;

final readonly class CreateInitialOwner
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private HashAdministratorPassword $hashAdministratorPassword,
        private AdministratorTotpAlgorithm $totpAlgorithm,
        private AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        private SecretCipher $secretCipher,
        private InitialOwnerRepository $initialOwnerRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function create(
        string $loginId,
        string $displayName,
        #[\SensitiveParameter] string $plainPassword,
        #[\SensitiveParameter] string $totpSecret,
        #[\SensitiveParameter] string $confirmationCode,
        #[\SensitiveParameter] ?string $initialSetupToken = null,
    ): ?CreatedInitialOwner {
        $plainRecoveryCodes = [];
        $created = false;

        try {
            $createdAt = $this->clock->now();
            $matchedTimeStep = $this->totpAlgorithm->matchTimeStep($totpSecret, $confirmationCode, $createdAt);
            if ($matchedTimeStep === null) {
                return null;
            }

            $administratorId = $this->idGenerator->generate();
            $owner = new Administrator(
                $administratorId,
                $loginId,
                $displayName,
                AdministratorRole::Owner,
                AdministratorStatus::Pending,
                $this->hashAdministratorPassword->hash($plainPassword),
                1,
                $createdAt,
                null,
                null,
                null,
                null,
                $createdAt,
                $createdAt,
                0,
            );
            $plainRecoveryCodes = $this->recoveryCodeAlgorithm->generate();
            $recoveryCodes = $this->createRecoveryCodes($administratorId, $plainRecoveryCodes, $createdAt);
            $credential = new AdministratorTotpCredential(
                $administratorId,
                $this->secretCipher->encrypt(
                    $totpSecret,
                    SecretPurpose::AdministratorTotpSecret,
                    $administratorId,
                ),
                $matchedTimeStep,
            );

            if ($initialSetupToken === null) {
                $this->initialOwnerRepository->create($owner, $credential, $recoveryCodes, $createdAt);
            } elseif (!$this->initialOwnerRepository->createUsingInitialSetupToken(
                hash('sha256', $initialSetupToken),
                $owner,
                $credential,
                $recoveryCodes,
                $createdAt,
            )) {
                return null;
            }

            $result = new CreatedInitialOwner($administratorId, $plainRecoveryCodes);
            $created = true;

            return $result;
        } finally {
            sodium_memzero($plainPassword);
            sodium_memzero($totpSecret);
            sodium_memzero($confirmationCode);
            if ($initialSetupToken !== null) {
                sodium_memzero($initialSetupToken);
            }

            if (!$created) {
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
