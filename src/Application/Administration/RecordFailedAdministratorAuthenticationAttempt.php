<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationAttemptThrottler;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateInterval;
use DateTimeImmutable;

final readonly class RecordFailedAdministratorAuthenticationAttempt
{
    public function __construct(
        private AuthenticationAttemptRepository $authenticationAttemptRepository,
        private AuthenticationAttemptThrottler $authenticationAttemptThrottler,
        private AuthenticationPolicyRepository $authenticationPolicyRepository,
        private Clock $clock,
        private IdGenerator $idGenerator,
    ) {
    }

    public function record(#[\SensitiveParameter] string $loginIdentifier, string $sourceIp): ?DateTimeImmutable
    {
        $now = $this->clock->now();
        $policy = $this->authenticationPolicyRepository->get();
        $loginIdentifierHash = hash('sha256', strtolower(trim($loginIdentifier)));
        $windowStartedAt = $now->sub(new DateInterval(sprintf('PT%dM', $policy->failureWindowMinutes)));
        $recentFailureCount = $this->authenticationAttemptRepository->countFailuresSince(
            $loginIdentifierHash,
            $sourceIp,
            $windowStartedAt,
        ) + 1;
        $retryAfter = $this->authenticationAttemptThrottler->retryAfter($policy, $recentFailureCount, $now);

        $this->authenticationAttemptRepository->record(new AuthenticationAttempt(
            $this->idGenerator->generate(),
            $loginIdentifierHash,
            $sourceIp,
            $now,
            'failure',
            $retryAfter,
        ));

        return $retryAfter;
    }
}
