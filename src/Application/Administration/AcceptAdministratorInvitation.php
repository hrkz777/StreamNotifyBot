<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorInvitationAcceptanceRepository;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use UnexpectedValueException;

final readonly class AcceptAdministratorInvitation
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private HashAdministratorPassword $hashAdministratorPassword,
        private AdministratorTotpAlgorithm $totpAlgorithm,
        private AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        private SecretCipher $secretCipher,
        private AdministratorInvitationAcceptanceRepository $repository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function accept(
        #[\SensitiveParameter] string $plainToken,
        #[\SensitiveParameter] string $plainPassword,
        #[\SensitiveParameter] string $totpSecret,
        #[\SensitiveParameter] string $confirmationCode,
    ): ?AcceptedAdministratorInvitation {
        $plainRecoveryCodes = [];
        $accepted = false;

        try {
            $acceptedAt = $this->clock->now();
            $tokenHash = hash('sha256', $plainToken);
            $administratorId = $this->repository->findTargetAdministratorId($tokenHash, $acceptedAt);
            if ($administratorId === null) {
                return null;
            }

            $matchedTimeStep = $this->totpAlgorithm->matchTimeStep($totpSecret, $confirmationCode, $acceptedAt);
            if ($matchedTimeStep === null) {
                return null;
            }

            $plainRecoveryCodes = $this->recoveryCodeAlgorithm->generate();
            $recoveryCodes = $this->createRecoveryCodes($administratorId, $plainRecoveryCodes, $acceptedAt);
            $credential = new AdministratorTotpCredential(
                $administratorId,
                $this->secretCipher->encrypt($totpSecret, SecretPurpose::AdministratorTotpSecret, $administratorId),
                $matchedTimeStep,
            );
            $passwordHash = $this->hashAdministratorPassword->hash($plainPassword);

            if (!$this->repository->accept($tokenHash, $administratorId, $passwordHash, $credential, $recoveryCodes, $acceptedAt)) {
                return null;
            }

            $accepted = true;

            return new AcceptedAdministratorInvitation($administratorId, $plainRecoveryCodes);
        } finally {
            sodium_memzero($plainToken);
            sodium_memzero($plainPassword);
            sodium_memzero($totpSecret);
            sodium_memzero($confirmationCode);

            if (!$accepted) {
                self::eraseRecoveryCodes($plainRecoveryCodes);
            }
        }
    }

    /**
     * @param list<string> $plainRecoveryCodes
     * @return list<AdministratorRecoveryCode>
     */
    private function createRecoveryCodes(string $administratorId, array $plainRecoveryCodes, DateTimeImmutable $createdAt): array
    {
        if (count($plainRecoveryCodes) !== self::RECOVERY_CODE_COUNT) {
            throw new UnexpectedValueException('回復コード生成結果は10件である必要があります。');
        }

        $recoveryCodes = [];
        $hashes = [];
        $ids = [];
        foreach ($plainRecoveryCodes as $plainRecoveryCode) {
            $codeHash = $this->recoveryCodeAlgorithm->hash($plainRecoveryCode);
            $recoveryCodeId = $this->idGenerator->generate();
            if (isset($hashes[$codeHash]) || isset($ids[$recoveryCodeId])) {
                throw new UnexpectedValueException('回復コード生成結果に重複があります。');
            }

            $hashes[$codeHash] = true;
            $ids[$recoveryCodeId] = true;
            $recoveryCodes[] = new AdministratorRecoveryCode($recoveryCodeId, $administratorId, $codeHash, $createdAt, null);
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
