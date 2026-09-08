<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\FindAdministratorAuthenticationRetryAfter;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindAdministratorAuthenticationRetryAfterTest extends TestCase
{
    #[Test]
    public function itLooksUpTheNormalizedIdentifierWithinTheCurrentFailureWindow(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $retryAfter = $now->modify('+1 minute');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->expects(self::once())
            ->method('findRetryAfterSince')
            ->with(
                hash('sha256', 'system.owner'),
                '203.0.113.10',
                new DateTimeImmutable('2026-09-07 23:45:00+00:00'),
                $now,
            )
            ->willReturn($retryAfter);

        self::assertSame(
            $retryAfter,
            (new FindAdministratorAuthenticationRetryAfter(
                $attempts,
                $this->policyRepository($now),
                $this->clock($now),
            ))->find('  SYSTEM.OWNER  ', '203.0.113.10'),
        );
    }

    #[Test]
    public function itReturnsNullWhenNoActiveRetryDeadlineExists(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createStub(AuthenticationAttemptRepository::class);
        $attempts->method('findRetryAfterSince')->willReturn(null);

        self::assertNull((new FindAdministratorAuthenticationRetryAfter(
            $attempts,
            $this->policyRepository($now),
            $this->clock($now),
        ))->find('system.owner', '203.0.113.10'));
    }

    private function policyRepository(DateTimeImmutable $now): AuthenticationPolicyRepository
    {
        $repository = $this->createStub(AuthenticationPolicyRepository::class);
        $repository->method('get')->willReturn(new AuthenticationPolicy(
            AuthenticationPolicy::ID,
            30,
            12,
            10,
            15,
            5,
            15,
            null,
            $now,
            0,
        ));

        return $repository;
    }

    private function clock(DateTimeImmutable $now): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        return $clock;
    }
}
