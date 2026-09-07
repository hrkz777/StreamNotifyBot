<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorInvitationRepository;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorSetupTokenGenerator;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateInterval;

final readonly class IssueAdministratorInvitation
{
    public function __construct(private AdministratorInvitationRepository $repository, private AdministratorSetupTokenGenerator $tokenGenerator, private IdGenerator $idGenerator, private Clock $clock)
    {
    }

    public function issue(string $ownerId, string $loginId, string $displayName, AdministratorRole $role): IssuedInitialSetupToken
    {
        $tokenValue = $this->tokenGenerator->generate();
        $completed = false;
        try {
            $now = $this->clock->now();
            $administratorId = $this->idGenerator->generate();
            $expiresAt = $now->add(new DateInterval('PT30M'));
            $this->repository->create(
                new Administrator($administratorId, $loginId, $displayName, $role, AdministratorStatus::Pending, null, 1, null, null, null, null, null, $now, $now, 0),
                new AdministratorToken($this->idGenerator->generate(), $administratorId, AdministratorTokenPurpose::Invitation, hash('sha256', $tokenValue), $ownerId, 1, $now, $expiresAt, null, null),
            );
            $result = new IssuedInitialSetupToken($tokenValue, $expiresAt);
            $completed = true;

            return $result;
        } finally {
            if (!$completed) {
                sodium_memzero($tokenValue);
            }
        }
    }
}
