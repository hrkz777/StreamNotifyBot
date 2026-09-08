<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;

final readonly class RecordSuccessfulAdministratorAuthenticationAttempt
{
    public function __construct(
        private AuthenticationAttemptRepository $authenticationAttemptRepository,
        private Clock $clock,
        private IdGenerator $idGenerator,
    ) {
    }

    public function record(#[\SensitiveParameter] string $loginIdentifier, string $sourceIp): void
    {
        $this->authenticationAttemptRepository->record(new AuthenticationAttempt(
            $this->idGenerator->generate(),
            hash('sha256', strtolower(trim($loginIdentifier))),
            $sourceIp,
            $this->clock->now(),
            'success',
            null,
        ));
    }
}
