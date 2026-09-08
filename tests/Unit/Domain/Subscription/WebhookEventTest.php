<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Subscription;

use App\Domain\Subscription\WebhookEvent;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    #[Test]
    public function itCalculatesAStableBinaryPayloadHash(): void
    {
        $event = new WebhookEvent(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            '<feed>通知</feed>',
            new DateTimeImmutable('2026-09-08 00:00:00+09:00'),
        );

        self::assertSame(
            'e7735d5b25f6d35f282aa5b22926891812aa435189b81caedd3b327f34e34506',
            bin2hex($event->payloadHash()),
        );
        self::assertSame('2026-09-07 15:00:00+00:00', $event->receivedAt->format('Y-m-d H:i:sP'));
    }

    #[Test]
    public function itRejectsAnEmptyPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookEvent(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            '',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        );
    }
}
