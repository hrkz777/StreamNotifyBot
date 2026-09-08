<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AuthenticationAttemptThrottler;
use App\Domain\Administration\AuthenticationPolicy;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticationAttemptThrottlerTest extends TestCase
{
    private AuthenticationAttemptThrottler $throttler;
    private DateTimeImmutable $attemptedAt;

    protected function setUp(): void
    {
        $this->throttler = new AuthenticationAttemptThrottler();
        $this->attemptedAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
    }

    #[Test]
    public function itDoesNotDelayAttemptsBelowTheFailureThreshold(): void
    {
        self::assertNull($this->throttler->retryAfter($this->policy(), 4, $this->attemptedAt));
    }

    #[Test]
    public function itStartsWithOneMinuteAndExponentiallyIncreasesTheDelay(): void
    {
        $policy = $this->policy();

        self::assertSame('2026-09-08 00:01:00.000000', $this->throttler->retryAfter($policy, 5, $this->attemptedAt)?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-08 00:02:00.000000', $this->throttler->retryAfter($policy, 6, $this->attemptedAt)?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-08 00:04:00.000000', $this->throttler->retryAfter($policy, 7, $this->attemptedAt)?->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function itCapsTheDelayAtTheConfiguredMaximum(): void
    {
        self::assertSame(
            '2026-09-08 00:15:00.000000',
            $this->throttler->retryAfter($this->policy(), 100, $this->attemptedAt)?->format('Y-m-d H:i:s.u'),
        );
    }

    #[Test]
    public function itRejectsANegativeFailureCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->throttler->retryAfter($this->policy(), -1, $this->attemptedAt);
    }

    private function policy(): AuthenticationPolicy
    {
        return new AuthenticationPolicy(
            AuthenticationPolicy::ID,
            30,
            12,
            10,
            15,
            5,
            15,
            null,
            $this->attemptedAt,
            0,
        );
    }
}
