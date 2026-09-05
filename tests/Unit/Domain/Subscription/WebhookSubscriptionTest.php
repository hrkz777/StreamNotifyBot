<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Subscription;

use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookSubscriptionTest extends TestCase
{
    #[Test]
    public function itNormalizesDatesToUtc(): void
    {
        $subscription = $this->subscription(
            new DateTimeImmutable('2026-09-03 10:00:00+09:00'),
            new DateTimeImmutable('2026-09-03 09:00:00+09:00'),
        );

        self::assertSame('2026-09-03 01:00:00.000000+00:00', $subscription->expiresAt?->format('Y-m-d H:i:s.uP'));
        self::assertSame('2026-09-03 00:00:00.000000+00:00', $subscription->renewAfter?->format('Y-m-d H:i:s.uP'));
    }

    #[Test]
    public function itRejectsARenewalAfterExpiration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->subscription(
            new DateTimeImmutable('2026-09-03 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-03 00:00:01+00:00'),
        );
    }

    #[Test]
    public function itRejectsAnIncompleteProcessingLease(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000301',
            '01990d4a-0000-7000-8000-000000000211',
            'stream.online',
            null,
            WebhookSubscriptionStatus::Pending,
            null,
            null,
            null,
            0,
            '00112233445566778899aabbccddeeff',
            null,
            null,
        );
    }

    #[Test]
    public function itCreatesAnActiveResultFromAClaimedSubscription(): void
    {
        $claimed = $this->claimedSubscription();

        $active = $claimed->activate(
            'new-external-id',
            new DateTimeImmutable('2026-09-04 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-03 12:00:00+00:00'),
        );

        self::assertSame(WebhookSubscriptionStatus::Active, $active->status);
        self::assertSame('new-external-id', $active->externalSubscriptionId);
        self::assertSame(0, $active->failureCount);
        self::assertSame('00112233445566778899aabbccddeeff', $active->processingLeaseToken);
        self::assertNull($active->lastErrorCode);
    }

    #[Test]
    public function itSchedulesATransientFailureWithoutChangingTheCurrentStatus(): void
    {
        $retry = $this->claimedSubscription()->scheduleRetry(
            new DateTimeImmutable('2026-09-02 00:05:00+00:00'),
            'HTTP_503',
        );

        self::assertSame(WebhookSubscriptionStatus::Active, $retry->status);
        self::assertSame(3, $retry->failureCount);
        self::assertSame('HTTP_503', $retry->lastErrorCode);
        self::assertSame('00112233445566778899aabbccddeeff', $retry->processingLeaseToken);
    }

    #[Test]
    public function itWaitsForVerificationWithoutCountingAnAcceptedRequestAsAFailure(): void
    {
        $awaitingVerification = $this->claimedSubscription()->awaitVerification(
            new DateTimeImmutable('2026-09-02 00:05:00+00:00'),
        );

        self::assertSame(WebhookSubscriptionStatus::Active, $awaitingVerification->status);
        self::assertSame(0, $awaitingVerification->failureCount);
        self::assertNull($awaitingVerification->lastErrorCode);
        self::assertSame('2026-09-02 00:05:00', $awaitingVerification->renewAfter?->format('Y-m-d H:i:s'));
        self::assertSame('00112233445566778899aabbccddeeff', $awaitingVerification->processingLeaseToken);
    }

    #[Test]
    public function itDoesNotScheduleVerificationAfterTheCurrentExpiry(): void
    {
        $expiresAt = new DateTimeImmutable('2026-09-02 00:03:00+00:00');
        $subscription = new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000301',
            '01990d4a-0000-7000-8000-000000000211',
            'channel.feed',
            'existing-subscription',
            WebhookSubscriptionStatus::Active,
            $expiresAt,
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            2,
            '00112233445566778899aabbccddeeff',
            new DateTimeImmutable('2026-09-02 00:02:00+00:00'),
            'transport_error',
        );

        $result = $subscription->awaitVerification(
            new DateTimeImmutable('2026-09-02 00:05:00+00:00'),
        );

        self::assertEquals($expiresAt, $result->renewAfter);
        self::assertSame(0, $result->failureCount);
        self::assertNull($result->lastErrorCode);
    }

    #[Test]
    public function itMarksAPermanentFailureAsAnError(): void
    {
        $failed = $this->claimedSubscription()->failPermanently('HTTP_401');

        self::assertSame(WebhookSubscriptionStatus::Error, $failed->status);
        self::assertSame(3, $failed->failureCount);
        self::assertNull($failed->renewAfter);
        self::assertSame('HTTP_401', $failed->lastErrorCode);
    }

    #[Test]
    public function itRejectsAResultCreatedWithoutALease(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->subscription(
            new DateTimeImmutable('2026-09-03 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
        )->failPermanently('HTTP_401');
    }

    private function subscription(
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $renewAfter,
    ): WebhookSubscription {
        return new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000301',
            '01990d4a-0000-7000-8000-000000000211',
            'stream.online',
            'external-subscription-id',
            WebhookSubscriptionStatus::Active,
            $expiresAt,
            $renewAfter,
            null,
            0,
            null,
            null,
            null,
        );
    }

    private function claimedSubscription(): WebhookSubscription
    {
        return new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000301',
            '01990d4a-0000-7000-8000-000000000211',
            'stream.online',
            'external-subscription-id',
            WebhookSubscriptionStatus::Active,
            new DateTimeImmutable('2026-09-04 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            2,
            '00112233445566778899aabbccddeeff',
            new DateTimeImmutable('2026-09-02 00:02:00+00:00'),
            'HTTP_503',
        );
    }
}
