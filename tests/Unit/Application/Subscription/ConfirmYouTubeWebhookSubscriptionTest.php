<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\ConfirmYouTubeWebhookSubscription;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmYouTubeWebhookSubscriptionTest extends TestCase
{
    #[Test]
    public function itRejectsAnUnknownSubscriptionWithoutActivatingIt(): void
    {
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn(null);
        $catalog = $this->createMock(StreamerCatalogRepository::class);
        $catalog->expects(self::never())->method('findPlatformAccountById');
        $service = new ConfirmYouTubeWebhookSubscription($subscriptions, $catalog, $this->clock());

        self::assertFalse($service->confirm(
            '01990d4a-0000-7000-8000-000000000401',
            'https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxxxxxxxxxxxxxxxxxxxx',
        ));
    }

    #[Test]
    public function itRejectsASubscriptionTypeOtherThanYouTubeChannelFeed(): void
    {
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn(WebhookSubscription::pending(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            'stream.online',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        ));
        $catalog = $this->createMock(StreamerCatalogRepository::class);
        $catalog->expects(self::never())->method('findPlatformAccountById');
        $service = new ConfirmYouTubeWebhookSubscription($subscriptions, $catalog, $this->clock());

        self::assertFalse($service->confirm(
            '01990d4a-0000-7000-8000-000000000401',
            'https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxxxxxxxxxxxxxxxxxxxx',
        ));
    }

    #[Test]
    public function itConfirmsTheExpectedYouTubeTopic(): void
    {
        $subscription = WebhookSubscription::pending(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            'channel.feed',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        );
        $subscriptions = $this->createMock(WebhookSubscriptionRepository::class);
        $subscriptions->expects(self::once())->method('findById')->willReturn($subscription);
        $subscriptions->expects(self::once())
            ->method('confirmVerification')
            ->with(
                $subscription->id,
                new DateTimeImmutable('2026-09-09 00:00:00+00:00'),
            )
            ->willReturn(true);
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn(new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000403',
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
        ));
        $service = new ConfirmYouTubeWebhookSubscription($subscriptions, $catalog, $this->clock());

        self::assertTrue($service->confirm(
            $subscription->id,
            'https://www.youtube.com/feeds/videos.xml?channel_id=UCabcdefghijklmnopqrstuv',
        ));
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:00:00+00:00'));

        return $clock;
    }
}
