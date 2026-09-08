<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\ReauthenticateAdministrator;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpVerifier;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReauthenticateAdministratorTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000520';

    #[Test]
    public function itFailsBeforeTotpVerificationWhenThePasswordDoesNotMatch(): void
    {
        $repository = $this->createMock(AdministratorRepository::class);
        $repository->expects(self::once())->method('findById')->with(self::ADMINISTRATOR_ID)->willReturn($this->administrator());
        $hasher = $this->createMock(AdministratorPasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->with('$argon2id$test-fixture', 'incorrect')->willReturn(false);
        $totpVerifier = $this->createMock(AdministratorTotpVerifier::class);
        $totpVerifier->expects(self::never())->method('verify');
        $sessions = $this->createMock(AdministratorSessionRepository::class);
        $sessions->expects(self::never())->method('markReauthenticated');

        self::assertFalse($this->service($repository, $hasher, $totpVerifier, $sessions)->reauthenticate(self::ADMINISTRATOR_ID, 1, 'session-id', 'incorrect', '123456'));
    }

    #[Test]
    public function itFailsBeforeUpdatingTheSessionWhenTotpVerificationFails(): void
    {
        $repository = $this->createMock(AdministratorRepository::class);
        $repository->expects(self::once())->method('findById')->with(self::ADMINISTRATOR_ID)->willReturn($this->administrator());
        $hasher = $this->createStub(AdministratorPasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $totpVerifier = $this->createMock(AdministratorTotpVerifier::class);
        $totpVerifier->expects(self::once())->method('verify')->with(self::ADMINISTRATOR_ID, '000000')->willReturn(false);
        $sessions = $this->createMock(AdministratorSessionRepository::class);
        $sessions->expects(self::never())->method('markReauthenticated');

        self::assertFalse($this->service($repository, $hasher, $totpVerifier, $sessions)->reauthenticate(self::ADMINISTRATOR_ID, 1, 'session-id', 'test-only-password', '000000'));
    }

    #[Test]
    public function itMarksTheCurrentSessionAfterPasswordAndTotpVerification(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00');
        $repository = $this->createMock(AdministratorRepository::class);
        $repository->expects(self::once())->method('findById')->with(self::ADMINISTRATOR_ID)->willReturn($this->administrator());
        $hasher = $this->createStub(AdministratorPasswordHasher::class);
        $hasher->method('verify')->willReturn(true);
        $totpVerifier = $this->createStub(AdministratorTotpVerifier::class);
        $totpVerifier->method('verify')->willReturn(true);
        $sessions = $this->createMock(AdministratorSessionRepository::class);
        $sessions->expects(self::once())
            ->method('markReauthenticated')
            ->with(hash('sha256', 'session-id'), self::ADMINISTRATOR_ID, 1, $now)
            ->willReturn(true);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        self::assertTrue((new ReauthenticateAdministrator($repository, $hasher, $totpVerifier, $sessions, $clock))->reauthenticate(self::ADMINISTRATOR_ID, 1, 'session-id', 'test-only-password', '123456'));
    }

    private function service(
        AdministratorRepository $repository,
        AdministratorPasswordHasher $hasher,
        AdministratorTotpVerifier $totpVerifier,
        AdministratorSessionRepository $sessions,
    ): ReauthenticateAdministrator {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:01:00+00:00'));

        return new ReauthenticateAdministrator($repository, $hasher, $totpVerifier, $sessions, $clock);
    }

    private function administrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');

        return new Administrator(self::ADMINISTRATOR_ID, 'test.owner', 'テスト管理者', AdministratorRole::Owner, AdministratorStatus::Active, '$argon2id$test-fixture', 1, $now, $now, null, null, null, $now, $now, 0);
    }
}
