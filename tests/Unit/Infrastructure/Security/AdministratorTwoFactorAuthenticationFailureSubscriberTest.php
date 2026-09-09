<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Administration\RecordFailedAdministratorAuthenticationAttempt;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationAttemptThrottler;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Security\AdministratorTwoFactorAuthenticationFailureSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AdministratorTwoFactorAuthenticationFailureSubscriberTest extends TestCase
{
    #[Test]
    public function itRecordsTheAdministratorWhenTwoFactorAuthenticationFails(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->method('countFailuresSince')->willReturn(0);
        $attempts->expects(self::once())->method('record')->with(self::callback(
            static fn (AuthenticationAttempt $attempt): bool => $attempt->loginIdentifierHash === hash('sha256', 'system.owner')
                && $attempt->sourceIp === '203.0.113.10'
                && $attempt->result === 'failure',
        ));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user($now));
        $request = Request::create('/admin/2fa/check', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        (new AdministratorTwoFactorAuthenticationFailureSubscriber($this->recorder($attempts, $now)))
            ->onTwoFactorAuthenticationFailure(new TwoFactorAuthenticationEvent($request, $token));
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

    private function user(DateTimeImmutable $now): AdministratorSecurityUser
    {
        return AdministratorSecurityUser::fromAdministrator(new Administrator(
            '0199d534-0000-7000-8000-000000000002',
            'system.owner',
            '管理者',
            AdministratorRole::Owner,
            AdministratorStatus::Active,
            '$argon2id$test-password-hash',
            1,
            $now,
            $now,
            null,
            null,
            null,
            $now,
            $now,
            0,
        ));
    }
}
