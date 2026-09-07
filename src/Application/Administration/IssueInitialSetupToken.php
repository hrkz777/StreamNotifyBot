<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorSetupTokenGenerator;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\AdministratorTokenRepository;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateInterval;

final readonly class IssueInitialSetupToken
{
    private const string TOKEN_LIFETIME = 'PT30M';

    public function __construct(
        private AuthenticationPolicyRepository $authenticationPolicyRepository,
        private AdministratorTokenRepository $administratorTokenRepository,
        private AdministratorSetupTokenGenerator $tokenGenerator,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function issue(): IssuedInitialSetupToken
    {
        if ($this->authenticationPolicyRepository->get()->initialSetupCompletedAt !== null) {
            throw new InitialSetupAlreadyCompleted();
        }

        $plainToken = $this->tokenGenerator->generate();
        $issued = false;

        try {
            $createdAt = $this->clock->now();
            $expiresAt = $createdAt->add(new DateInterval(self::TOKEN_LIFETIME));
            $this->administratorTokenRepository->add(new AdministratorToken(
                id: $this->idGenerator->generate(),
                administratorId: null,
                purpose: AdministratorTokenPurpose::InitialSetup,
                tokenHash: hash('sha256', $plainToken),
                createdByAdministratorId: null,
                authenticationVersion: null,
                createdAt: $createdAt,
                expiresAt: $expiresAt,
                consumedAt: null,
                revokedAt: null,
            ));
            $result = new IssuedInitialSetupToken($plainToken, $expiresAt);
            $issued = true;

            return $result;
        } finally {
            if (!$issued) {
                sodium_memzero($plainToken);
            }
        }
    }
}
