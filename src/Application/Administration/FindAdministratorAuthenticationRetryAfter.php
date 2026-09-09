<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use DateInterval;
use DateTimeImmutable;

final readonly class FindAdministratorAuthenticationRetryAfter
{
    public function __construct(
        private AuthenticationAttemptRepository $authenticationAttemptRepository,
        private AuthenticationPolicyRepository $authenticationPolicyRepository,
        private Clock $clock,
    ) {
    }

    public function find(#[\SensitiveParameter] string $loginIdentifier, string $sourceIp): ?DateTimeImmutable
    {
        $now = $this->clock->now();
        $policy = $this->authenticationPolicyRepository->get();
        $windowStartedAt = $now->sub(new DateInterval(sprintf('PT%dM', $policy->failureWindowMinutes)));

        return $this->authenticationAttemptRepository->findRetryAfterSince(
            hash('sha256', strtolower(trim($loginIdentifier))),
            $sourceIp,
            $windowStartedAt,
            $now,
        );
    }
}
