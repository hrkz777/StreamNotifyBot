<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\ProcessWebhookEvents;
use App\Application\Subscription\ProcessWebhookEventsInput;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventLease;
use App\Domain\Subscription\WebhookEventRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\LeaseTokenGenerator;
use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessWebhookEventsTest extends TestCase
{
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000401';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000402';

    #[Test]
    public function itProcessesAnAtomEventForTheSubscribedChannel(): void
    {
        $event = new WebhookEvent('01990d4a-0000-7000-8000-000000000403', self::SUBSCRIPTION_ID, $this->feed('UCabcdefghijklmnopqrstuv'), new DateTimeImmutable('2026-09-09 00:00:00+00:00'));
        $lease = new WebhookEventLease($event, '00112233445566778899aabbccddeeff', new DateTimeImmutable('2026-09-09 00:02:00+00:00'));
        $events = $this->createMock(WebhookEventRepository::class);
        $events->expects(self::once())->method('claimPending')->with(50, '00112233445566778899aabbccddeeff', 120)->willReturn([$lease]);
        $events->expects(self::once())->method('markProcessed')->with($lease)->willReturn(true);
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn($this->subscription());
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn($this->account());

        $result = $this->service($events, $subscriptions, $catalog)->process(new ProcessWebhookEventsInput(50, 45, 120));

        self::assertSame(1, $result->claimedCount);
        self::assertSame(1, $result->processedCount);
        self::assertSame(0, $result->discardedCount);
    }

    #[Test]
    public function itDiscardsAnEventForAnotherChannel(): void
    {
        $event = new WebhookEvent('01990d4a-0000-7000-8000-000000000404', self::SUBSCRIPTION_ID, $this->feed('UCzzzzzzzzzzzzzzzzzzzzzz'), new DateTimeImmutable('2026-09-09 00:00:00+00:00'));
        $lease = new WebhookEventLease($event, '00112233445566778899aabbccddeeff', new DateTimeImmutable('2026-09-09 00:02:00+00:00'));
        $events = $this->createMock(WebhookEventRepository::class);
        $events->method('claimPending')->willReturn([$lease]);
        $events->expects(self::once())->method('markProcessed')->with($lease)->willReturn(true);
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn($this->subscription());
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn($this->account());

        $result = $this->service($events, $subscriptions, $catalog)->process(new ProcessWebhookEventsInput(50, 45, 120));

        self::assertSame(0, $result->processedCount);
        self::assertSame(1, $result->discardedCount);
    }

    private function service(WebhookEventRepository $events, WebhookSubscriptionRepository $subscriptions, StreamerCatalogRepository $catalog): ProcessWebhookEvents
    {
        return new ProcessWebhookEvents($events, $subscriptions, $catalog, new YouTubeAtomFeedParser(), new class () implements LeaseTokenGenerator {
            public function generate(): string
            {
                return '00112233445566778899aabbccddeeff';
            }
        }, new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-09 00:00:00+00:00');
            }
        });
    }

    private function subscription(): WebhookSubscription
    {
        return new WebhookSubscription(self::SUBSCRIPTION_ID, self::ACCOUNT_ID, 'channel.feed', null, WebhookSubscriptionStatus::Active, null, new DateTimeImmutable('2026-09-09 00:00:00+00:00'), null, 0, null, null, null);
    }

    private function account(): PlatformAccount
    {
        return new PlatformAccount(self::ACCOUNT_ID, '01990d4a-0000-7000-8000-000000000405', Platform::YouTube, 'UCabcdefghijklmnopqrstuv', '@channel', '@channel', '配信者名（未接続）', 'https://www.youtube.com/@channel', null, null, true, new DateTimeImmutable('2026-09-09 00:00:00+00:00'));
    }

    private function feed(string $channelId): string
    {
        return sprintf('<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015"><entry><yt:videoId>abcdefghijk</yt:videoId><yt:channelId>%s</yt:channelId></entry></feed>', $channelId);
    }
}
