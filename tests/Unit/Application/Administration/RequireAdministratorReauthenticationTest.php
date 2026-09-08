<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequireAdministratorReauthenticationTest extends TestCase
{
    #[Test]
    public function itAppliesThePolicyIntervalToTheCurrentSession(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:30:00+00:00');
        $policy = new AuthenticationPolicy(AuthenticationPolicy::ID, 30, 12, 10, 15, 5, 15, null, $now, 0);
        $policies = $this->createStub(AuthenticationPolicyRepository::class);
        $policies->method('get')->willReturn($policy);
        $sessions = $this->createMock(AdministratorSessionRepository::class);
        $sessions->expects(self::once())
            ->method('isReauthenticatedSince')
            ->with(
                hash('sha256', 'current-session'),
                '01990d4a-0000-7000-8000-000000000520',
                1,
                new DateTimeImmutable('2026-09-08 00:20:00+00:00'),
                $now,
            )
            ->willReturn(true);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        self::assertTrue((new RequireAdministratorReauthentication($sessions, $policies, $clock))->isSatisfied('01990d4a-0000-7000-8000-000000000520', 1, 'current-session'));
    }

    #[Test]
    public function itFailsClosedForAnEmptySessionId(): void
    {
        $sessions = $this->createMock(AdministratorSessionRepository::class);
        $sessions->expects(self::never())->method('isReauthenticatedSince');
        $policies = $this->createMock(AuthenticationPolicyRepository::class);
        $policies->expects(self::never())->method('get');

        self::assertFalse((new RequireAdministratorReauthentication($sessions, $policies, $this->createStub(Clock::class)))->isSatisfied('01990d4a-0000-7000-8000-000000000520', 1, ''));
    }
}
