<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpVerifier;
use App\Domain\System\Clock;
use InvalidArgumentException;

final readonly class ReauthenticateAdministrator
{
    public function __construct(
        private AdministratorRepository $administratorRepository,
        private AdministratorPasswordHasher $passwordHasher,
        private AdministratorTotpVerifier $totpVerifier,
        private AdministratorSessionRepository $sessionRepository,
        private Clock $clock,
    ) {
    }

    public function reauthenticate(
        string $administratorId,
        int $authenticationVersion,
        #[\SensitiveParameter] string $sessionId,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $totpCode,
    ): bool {
        if ($sessionId === '') {
            throw new InvalidArgumentException('セッションIDが空です。');
        }

        $administrator = $this->administratorRepository->findById($administratorId);
        if (
            $administrator === null
            || $administrator->status !== AdministratorStatus::Active
            || $administrator->authenticationVersion !== $authenticationVersion
            || $administrator->passwordHash === null
            || !$this->passwordHasher->verify($administrator->passwordHash, $password)
        ) {
            return false;
        }

        if (!$this->totpVerifier->verify($administratorId, $totpCode)) {
            return false;
        }

        return $this->sessionRepository->markReauthenticated(
            hash('sha256', $sessionId),
            $administratorId,
            $authenticationVersion,
            $this->clock->now(),
        );
    }
}
