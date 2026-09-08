<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Administration\FindAdministratorAuthenticationRetryAfter;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Security\AdministratorTwoFactorAuthenticationThrottleSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class AdministratorTwoFactorAuthenticationThrottleSubscriberTest extends TestCase
{
    #[Test]
    public function itRejectsAnActiveDelayedTwoFactorAttemptBeforeCodeVerification(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createStub(AuthenticationAttemptRepository::class);
        $attempts->method('findRetryAfterSince')->willReturn($now->modify('+1 minute'));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user($now));
        $request = Request::create('/admin/2fa/check', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $this->expectException(BadCredentialsException::class);
        (new AdministratorTwoFactorAuthenticationThrottleSubscriber($this->finder($attempts, $now)))
            ->onTwoFactorAuthenticationAttempt(new TwoFactorAuthenticationEvent($request, $token));
    }

    private function finder(AuthenticationAttemptRepository $attempts, DateTimeImmutable $now): FindAdministratorAuthenticationRetryAfter
    {
        $policies = $this->createStub(AuthenticationPolicyRepository::class);
        $policies->method('get')->willReturn(new AuthenticationPolicy(AuthenticationPolicy::ID, 30, 12, 10, 15, 5, 15, null, $now, 0));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        return new FindAdministratorAuthenticationRetryAfter($attempts, $policies, $clock);
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
