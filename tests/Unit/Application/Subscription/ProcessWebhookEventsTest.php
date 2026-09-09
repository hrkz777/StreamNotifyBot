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
use App\Domain\System\IdGenerator;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\PlatformVideo;
use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedParser;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetails;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsProvider;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsUnavailable;
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
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::once())->method('save')->with(self::callback(static fn (PlatformVideo $video): bool => $video->platformAccountId === self::ACCOUNT_ID && $video->externalVideoId === 'abcdefghijk'));

        $result = $this->service($events, $subscriptions, $catalog, $videos)->process(new ProcessWebhookEventsInput(50, 45, 120));

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

    #[Test]
    public function itReleasesAnEventWhenVideoDetailsAreTemporarilyUnavailable(): void
    {
        $event = new WebhookEvent('01990d4a-0000-7000-8000-000000000407', self::SUBSCRIPTION_ID, $this->feed('UCabcdefghijklmnopqrstuv'), new DateTimeImmutable('2026-09-09 00:00:00+00:00'));
        $lease = new WebhookEventLease($event, '00112233445566778899aabbccddeeff', new DateTimeImmutable('2026-09-09 00:02:00+00:00'));
        $events = $this->createMock(WebhookEventRepository::class);
        $events->method('claimPending')->willReturn([$lease]);
        $events->expects(self::once())->method('releaseClaim')->with($lease)->willReturn(true);
        $events->expects(self::never())->method('markProcessed');
        $subscriptions = $this->createStub(WebhookSubscriptionRepository::class);
        $subscriptions->method('findById')->willReturn($this->subscription());
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn($this->account());
        $provider = new class () implements YouTubeVideoDetailsProvider {
            /** @param list<mixed> $videoIds @return list<YouTubeVideoDetails> */
            public function fetch(array $videoIds): array
            {
                throw new YouTubeVideoDetailsUnavailable('一時的な障害');
            }
        };

        $service = $this->service($events, $subscriptions, $catalog, null, $provider);
        $result = $service->process(new ProcessWebhookEventsInput(50, 45, 120));

        self::assertSame(1, $result->releasedCount);
        self::assertSame(0, $result->processedCount);
    }

    private function service(WebhookEventRepository $events, WebhookSubscriptionRepository $subscriptions, StreamerCatalogRepository $catalog, ?PlatformVideoRepository $videos = null, ?YouTubeVideoDetailsProvider $provider = null): ProcessWebhookEvents
    {
        return new ProcessWebhookEvents($events, $subscriptions, $catalog, new YouTubeAtomFeedParser(), $provider ?? new class () implements YouTubeVideoDetailsProvider {
            /** @param list<mixed> $videoIds @return list<YouTubeVideoDetails> */
            public function fetch(array $videoIds): array
            {
                return [new YouTubeVideoDetails('abcdefghijk', 'UCabcdefghijklmnopqrstuv', '配信タイトル', new DateTimeImmutable('2026-09-09 00:00:00+00:00'), null, null, null, null, 'upcoming')];
            }
        }, $videos ?? $this->createStub(PlatformVideoRepository::class), new class () implements IdGenerator {
            public function generate(): string
            {
                return '01990d4a-0000-7000-8000-000000000406';
            }
        }, new class () implements LeaseTokenGenerator {
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
