<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\RecordFailedAdministratorAuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationAttemptThrottler;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordFailedAdministratorAuthenticationAttemptTest extends TestCase
{
    #[Test]
    public function itRecordsANormalizedLoginIdentifierHashAndReturnsTheDelay(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->expects(self::once())
            ->method('countFailuresSince')
            ->with(
                hash('sha256', 'system.owner'),
                '203.0.113.10',
                new DateTimeImmutable('2026-09-07 23:45:00+00:00'),
            )
            ->willReturn(4);
        $attempts->expects(self::once())
            ->method('record')
            ->with(self::callback(static fn (AuthenticationAttempt $attempt): bool => $attempt->id === '0199d534-0000-7000-8000-000000000001'
                && $attempt->loginIdentifierHash === hash('sha256', 'system.owner')
                && $attempt->sourceIp === '203.0.113.10'
                && $attempt->attemptedAt == $now
                && $attempt->result === 'failure'
                && $attempt->retryAfter == $now->modify('+1 minute')));

        $record = new RecordFailedAdministratorAuthenticationAttempt(
            $attempts,
            new AuthenticationAttemptThrottler(),
            $this->policyRepository($now),
            $this->clock($now),
            $this->idGenerator(),
        );

        self::assertSame('2026-09-08 00:01:00.000000', $record->record('  SYSTEM.OWNER  ', '203.0.113.10')?->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function itRecordsNoRetryDeadlineBelowTheThreshold(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->method('countFailuresSince')->willReturn(3);
        $attempts->expects(self::once())
            ->method('record')
            ->with(self::callback(static fn (AuthenticationAttempt $attempt): bool => $attempt->retryAfter === null));

        $record = new RecordFailedAdministratorAuthenticationAttempt(
            $attempts,
            new AuthenticationAttemptThrottler(),
            $this->policyRepository($now),
            $this->clock($now),
            $this->idGenerator(),
        );

        self::assertNull($record->record('system.owner', '203.0.113.10'));
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

    private function idGenerator(): IdGenerator
    {
        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator->method('generate')->willReturn('0199d534-0000-7000-8000-000000000001');

        return $idGenerator;
    }
}
