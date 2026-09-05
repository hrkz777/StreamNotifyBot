<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AuthenticationPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticationPolicyTest extends TestCase
{
    #[Test]
    public function itAcceptsTheSecureDefaults(): void
    {
        $policy = $this->policy(idleTimeoutMinutes: 30, absoluteTimeoutHours: 12);

        self::assertSame(30, $policy->idleTimeoutMinutes);
        self::assertSame(12, $policy->absoluteTimeoutHours);
    }

    #[Test]
    public function itRejectsAnIdleTimeoutOutsideTheAbsoluteRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('無操作期限は5分以上120分以下で指定してください。');

        $this->policy(idleTimeoutMinutes: 4, absoluteTimeoutHours: 12);
    }

    #[Test]
    public function itRejectsAnAbsoluteTimeoutShorterThanTheIdleTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('絶対期限は無操作期限以上で指定してください。');

        $this->policy(idleTimeoutMinutes: 120, absoluteTimeoutHours: 1);
    }

    private function policy(int $idleTimeoutMinutes, int $absoluteTimeoutHours): AuthenticationPolicy
    {
        return new AuthenticationPolicy(
            id: AuthenticationPolicy::ID,
            idleTimeoutMinutes: $idleTimeoutMinutes,
            absoluteTimeoutHours: $absoluteTimeoutHours,
            reauthenticationMinutes: 10,
            failureWindowMinutes: 15,
            failureThreshold: 5,
            maximumDelayMinutes: 15,
            initialSetupCompletedAt: null,
            updatedAt: new DateTimeImmutable('2026-09-04 00:00:00.000000', new DateTimeZone('UTC')),
            lockVersion: 0,
        );
    }
}
