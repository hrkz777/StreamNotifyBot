<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Subscription;

use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventLease;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookEventLeaseTest extends TestCase
{
    #[Test]
    public function itNormalizesTheLeaseExpiryToUtc(): void
    {
        $lease = new WebhookEventLease($this->event(), '00112233445566778899aabbccddeeff', new DateTimeImmutable('2026-09-08 00:00:00+09:00'));

        self::assertSame('2026-09-07 15:00:00+00:00', $lease->until->format('Y-m-d H:i:sP'));
    }

    #[Test]
    public function itRejectsAnInvalidLeaseToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookEventLease($this->event(), 'invalid', new DateTimeImmutable('2026-09-08 00:00:00+00:00'));
    }

    private function event(): WebhookEvent
    {
        return new WebhookEvent(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            'event',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        );
    }
}
