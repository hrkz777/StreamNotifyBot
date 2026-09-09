<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\ReceiveYouTubeWebhookEvent;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReceiveYouTubeWebhookEventTest extends TestCase
{
    #[Test]
    public function itRecordsAnEventForAnActiveYouTubeSubscription(): void
    {
        $subscription = new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            'channel.feed',
            null,
            WebhookSubscriptionStatus::Active,
            null,
            new DateTimeImmutable('2026-09-09 00:00:00+00:00'),
            null,
            0,
            null,
            null,
            null,
        );
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn($subscription);
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn($this->account());
        $events = $this->createMock(WebhookEventRepository::class);
        $events->expects(self::once())
            ->method('record')
            ->with(self::callback(static function (WebhookEvent $event): bool {
                self::assertSame('01990d4a-0000-7000-8000-000000000403', $event->id);
                self::assertSame('<feed>通知</feed>', $event->payload);

                return true;
            }))
            ->willReturn(true);
        $service = new ReceiveYouTubeWebhookEvent($subscriptions, $catalog, $events, $this->idGenerator(), $this->clock());

        self::assertTrue($service->receive($subscription->id, '<feed>通知</feed>'));
    }

    private function account(): PlatformAccount
    {
        return new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000402',
            '01990d4a-0000-7000-8000-000000000404',
            Platform::YouTube,
            'UCabcdefghijklmnopqrstuv',
            '@channel',
            '@channel',
            'チャンネル',
            'https://www.youtube.com/@channel',
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        );
    }

    private function idGenerator(): IdGenerator
    {
        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator->method('generate')->willReturn('01990d4a-0000-7000-8000-000000000403');

        return $idGenerator;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:00:00+00:00'));

        return $clock;
    }
}
