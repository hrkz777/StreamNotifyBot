<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use DateInterval;

final readonly class RequireAdministratorReauthentication
{
    public function __construct(
        private AdministratorSessionRepository $sessionRepository,
        private AuthenticationPolicyRepository $authenticationPolicyRepository,
        private Clock $clock,
    ) {
    }

    public function isSatisfied(string $administratorId, int $authenticationVersion, #[\SensitiveParameter] string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $now = $this->clock->now();
        $policy = $this->authenticationPolicyRepository->get();
        $requiredSince = $now->sub(new DateInterval(sprintf('PT%dM', $policy->reauthenticationMinutes)));

        return $this->sessionRepository->isReauthenticatedSince(
            hash('sha256', $sessionId),
            $administratorId,
            $authenticationVersion,
            $requiredSince,
            $now,
        );
    }
}
