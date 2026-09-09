<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Administration\RecordFailedAdministratorAuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationAttemptThrottler;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Security\AdministratorAuthenticationAttemptSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class AdministratorAuthenticationAttemptSubscriberTest extends TestCase
{
    #[Test]
    public function itRecordsOnlyPrimaryAdministratorLoginFailures(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->method('countFailuresSince')->willReturn(0);
        $attempts->expects(self::once())->method('record')->with(self::callback(
            static fn (AuthenticationAttempt $attempt): bool => $attempt->loginIdentifierHash === hash('sha256', 'system.owner')
                && $attempt->sourceIp === '203.0.113.10'
                && $attempt->result === 'failure',
        ));

        $subscriber = new AdministratorAuthenticationAttemptSubscriber($this->recorder($attempts, $now));
        $subscriber->onLoginFailure($this->event(Request::create('/admin/login', 'POST', ['login_id' => ' SYSTEM.OWNER '], [], [], ['REMOTE_ADDR' => '203.0.113.10'])));
    }

    #[Test]
    public function itIgnoresOtherAuthenticationFailures(): void
    {
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->expects(self::never())->method('countFailuresSince');
        $attempts->expects(self::never())->method('record');
        $subscriber = new AdministratorAuthenticationAttemptSubscriber($this->recorder($attempts, new DateTimeImmutable('2026-09-08 00:00:00+00:00')));

        $subscriber->onLoginFailure($this->event(Request::create('/admin/2fa', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10'])));
    }

    private function recorder(AuthenticationAttemptRepository $attempts, DateTimeImmutable $now): RecordFailedAdministratorAuthenticationAttempt
    {
        $policies = $this->createStub(AuthenticationPolicyRepository::class);
        $policies->method('get')->willReturn(new AuthenticationPolicy(AuthenticationPolicy::ID, 30, 12, 10, 15, 5, 15, null, $now, 0));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $ids = $this->createStub(IdGenerator::class);
        $ids->method('generate')->willReturn('0199d534-0000-7000-8000-000000000001');

        return new RecordFailedAdministratorAuthenticationAttempt($attempts, new AuthenticationAttemptThrottler(), $policies, $clock, $ids);
    }

    private function event(Request $request): LoginFailureEvent
    {
        return new LoginFailureEvent(
            new BadCredentialsException(),
            $this->createStub(AuthenticatorInterface::class),
            $request,
            null,
            'main',
        );
    }
}
