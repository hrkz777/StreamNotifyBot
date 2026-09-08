<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\RecordSuccessfulAdministratorAuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordSuccessfulAdministratorAuthenticationAttemptTest extends TestCase
{
    #[Test]
    public function itRecordsANormalizedSuccessfulAttemptWithoutARetryDeadline(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->expects(self::once())->method('record')->with(self::callback(
            static fn (AuthenticationAttempt $attempt): bool => $attempt->id === '0199d534-0000-7000-8000-000000000001'
                && $attempt->loginIdentifierHash === hash('sha256', 'system.owner')
                && $attempt->sourceIp === '203.0.113.10'
                && $attempt->attemptedAt == $now
                && $attempt->result === 'success'
                && $attempt->retryAfter === null,
        ));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $ids = $this->createStub(IdGenerator::class);
        $ids->method('generate')->willReturn('0199d534-0000-7000-8000-000000000001');
        $administrators = $this->createMock(AdministratorRepository::class);
        $administrators->expects(self::once())
            ->method('markLoggedIn')
            ->with('0199d534-0000-7000-8000-000000000002', 1, $now)
            ->willReturn(true);

        (new RecordSuccessfulAdministratorAuthenticationAttempt($attempts, $administrators, $clock, $ids))
            ->record('0199d534-0000-7000-8000-000000000002', 1, ' SYSTEM.OWNER ', '203.0.113.10');
    }
}
