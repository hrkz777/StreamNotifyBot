<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Job;

use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JobPolicyTest extends TestCase
{
    #[Test]
    public function itAcceptsTheDocumentedSubscriptionRenewalDefaults(): void
    {
        $policy = $this->policy();

        self::assertSame(JobType::SubscriptionRenewal, $policy->jobType);
        self::assertSame(20, $policy->batchSize);
        self::assertSame(45, $policy->maxRuntimeSeconds);
        self::assertSame(8, $policy->maxAttempts);
        self::assertSame(60, $policy->retryInitialDelaySeconds);
        self::assertSame(3600, $policy->retryMaxDelaySeconds);
        self::assertSame(2.0, $policy->backoffMultiplier);
        self::assertSame(20, $policy->jitterPercent);
        self::assertSame(120, $policy->leaseSeconds);
        self::assertTrue($policy->isEnabled);
    }

    #[Test]
    public function itRejectsAnUnsafeBatchSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(batchSize: 0);
    }

    #[Test]
    public function itRejectsARetryMaximumBelowTheInitialDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(retryInitialDelaySeconds: 60, retryMaxDelaySeconds: 59);
    }

    #[Test]
    public function itRejectsALeaseThatDoesNotCoverTheRuntimeMargin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(maxRuntimeSeconds: 45, leaseSeconds: 74);
    }

    #[Test]
    public function itRejectsANonFiniteBackoffMultiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobPolicy(
            '01990d4a-0000-7000-8000-000000000403',
            JobType::SubscriptionRenewal,
            20,
            45,
            8,
            60,
            3600,
            NAN,
            20,
            120,
            true,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            0,
        );
    }

    #[Test]
    public function itRejectsANegativeLockVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy(lockVersion: -1);
    }

    private function policy(
        int $batchSize = 20,
        int $maxRuntimeSeconds = 45,
        int $retryInitialDelaySeconds = 60,
        int $retryMaxDelaySeconds = 3600,
        int $leaseSeconds = 120,
        int $lockVersion = 0,
    ): JobPolicy {
        return new JobPolicy(
            '01990d4a-0000-7000-8000-000000000403',
            JobType::SubscriptionRenewal,
            $batchSize,
            $maxRuntimeSeconds,
            8,
            $retryInitialDelaySeconds,
            $retryMaxDelaySeconds,
            2.0,
            20,
            $leaseSeconds,
            true,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            $lockVersion,
        );
    }
}
