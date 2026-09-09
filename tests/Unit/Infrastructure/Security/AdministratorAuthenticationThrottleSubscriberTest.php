<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Administration\FindAdministratorAuthenticationRetryAfter;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorAuthenticationThrottleSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdministratorAuthenticationThrottleSubscriberTest extends TestCase
{
    #[Test]
    public function itRedirectsAnActiveDelayedLoginBeforeTheFirewallAuthenticatesIt(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createMock(AuthenticationAttemptRepository::class);
        $attempts->expects(self::once())->method('findRetryAfterSince')->willReturn($now->modify('+1 minute'));
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())->method('generate')->with('admin_login')->willReturn('/admin/login');
        $request = Request::create('/admin/login', 'POST', ['login_id' => 'system.owner'], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        (new AdministratorAuthenticationThrottleSubscriber($this->finder($attempts, $now), $urls))->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame('/admin/login', $event->getResponse()->headers->get('Location'));
        self::assertTrue($request->getSession()->get(AdministratorAuthenticationThrottleSubscriber::AUTHENTICATION_FAILED_SESSION_KEY));
    }

    #[Test]
    public function itDoesNotInterruptAnEligibleLogin(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempts = $this->createStub(AuthenticationAttemptRepository::class);
        $attempts->method('findRetryAfterSince')->willReturn(null);
        $request = Request::create('/admin/login', 'POST', ['login_id' => 'system.owner'], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        (new AdministratorAuthenticationThrottleSubscriber($this->finder($attempts, $now), $this->createStub(UrlGeneratorInterface::class)))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function finder(AuthenticationAttemptRepository $attempts, DateTimeImmutable $now): FindAdministratorAuthenticationRetryAfter
    {
        $policies = $this->createStub(AuthenticationPolicyRepository::class);
        $policies->method('get')->willReturn(new AuthenticationPolicy(AuthenticationPolicy::ID, 30, 12, 10, 15, 5, 15, null, $now, 0));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        return new FindAdministratorAuthenticationRetryAfter($attempts, $policies, $clock);
    }
}
