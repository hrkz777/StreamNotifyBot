<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;

final readonly class RecordSuccessfulAdministratorAuthenticationAttempt
{
    public function __construct(
        private AuthenticationAttemptRepository $authenticationAttemptRepository,
        private AdministratorRepository $administratorRepository,
        private Clock $clock,
        private IdGenerator $idGenerator,
    ) {
    }

    public function record(
        string $administratorId,
        int $authenticationVersion,
        #[\SensitiveParameter] string $loginIdentifier,
        string $sourceIp,
    ): void {
        $now = $this->clock->now();
        $this->authenticationAttemptRepository->record(new AuthenticationAttempt(
            $this->idGenerator->generate(),
            hash('sha256', strtolower(trim($loginIdentifier))),
            $sourceIp,
            $now,
            'success',
            null,
        ));
        $this->administratorRepository->markLoggedIn($administratorId, $authenticationVersion, $now);
    }
}
