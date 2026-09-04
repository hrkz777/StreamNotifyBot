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
}
