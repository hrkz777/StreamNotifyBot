<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Stream\EnqueueStreamNotifications;
use App\Application\Subscription\SyncTwitCastingStreams;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\Stream\StreamNotificationSchedule;
use App\Domain\Stream\StreamNotificationType;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Platform\TwitCasting\TwitCastingLiveStatus;
use App\Infrastructure\Platform\TwitCasting\TwitCastingLiveStatusProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncTwitCastingStreamsTest extends TestCase
{
    #[Test]
    public function itSavesLiveStreamsAndEnqueuesTheirStartedNotification(): void
    {
        $now = new DateTimeImmutable('2026-09-12T00:00:00Z');
        $liveAccount = $this->account('01990d4a-0000-7000-8000-000000000811', 'live-user');
        $offlineAccount = $this->account('01990d4a-0000-7000-8000-000000000812', 'offline-user');
        $catalog = $this->createMock(StreamerCatalogRepository::class);
        $catalog->expects(self::once())->method('findEnabledPlatformAccounts')->with(Platform::TwitCasting)->willReturn([$liveAccount, $offlineAccount]);
        $catalog->expects(self::exactly(2))->method('recordPolled')->willReturnCallback(static function (string $accountId, DateTimeImmutable $polledAt) use ($liveAccount, $offlineAccount, $now): bool {
            self::assertContains($accountId, [$liveAccount->id, $offlineAccount->id]);
            self::assertEquals($now, $polledAt);

            return true;
        });
        $provider = new class ($now) implements TwitCastingLiveStatusProvider {
            public function __construct(private DateTimeImmutable $now)
            {
            }

            public function fetch(string $userId): TwitCastingLiveStatus
            {
                return $userId === 'live-user'
                    ? new TwitCastingLiveStatus($userId, true, '1234567890', 'ライブ配信', $this->now)
                    : new TwitCastingLiveStatus($userId, false, null, null, null);
            }
        };
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::once())->method('save')->willReturnCallback(static function (PlatformVideo $video) use ($liveAccount, $now): string {
            self::assertSame($liveAccount->id, $video->platformAccountId);
            self::assertSame('1234567890', $video->externalVideoId);
            self::assertSame('ライブ配信', $video->title);
            self::assertEquals($now, $video->actualStartAt);
            self::assertSame('live', $video->lifecycleState);

            return '01990d4a-0000-7000-8000-000000000813';
        });
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::once())->method('enqueue')->willReturnCallback(static function (StreamNotificationOutbox $notification) use ($now): void {
            self::assertSame('01990d4a-0000-7000-8000-000000000813', $notification->platformVideoId);
            self::assertSame(StreamNotificationType::Started, $notification->type);
            self::assertSame($now, $notification->occurredAt);
        });

        $service = new SyncTwitCastingStreams(
            $catalog,
            $provider,
            $videos,
            new EnqueueStreamNotifications(new StreamNotificationSchedule(), $outbox, $this->idGenerator(), $this->clock($now)),
            $this->idGenerator(),
            $this->clock($now),
        );

        self::assertSame(1, $service->sync());
    }

    #[Test]
    public function itSkipsOfflineStreams(): void
    {
        $account = $this->account('01990d4a-0000-7000-8000-000000000814', 'offline-user');
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findEnabledPlatformAccounts')->willReturn([$account]);
        $provider = new class () implements TwitCastingLiveStatusProvider {
            public function fetch(string $userId): TwitCastingLiveStatus
            {
                return new TwitCastingLiveStatus($userId, false, null, null, null);
            }
        };
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::never())->method('save');
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::never())->method('enqueue');

        $service = new SyncTwitCastingStreams($catalog, $provider, $videos, new EnqueueStreamNotifications(new StreamNotificationSchedule(), $outbox, $this->idGenerator(), $this->clock(new DateTimeImmutable('2026-09-12T00:00:00Z'))), $this->idGenerator(), $this->clock(new DateTimeImmutable('2026-09-12T00:00:00Z')));

        self::assertSame(0, $service->sync());
    }

    #[Test]
    public function itClosesAnUnreportedLiveStreamAndEnqueuesEndedNotification(): void
    {
        $now = new DateTimeImmutable('2026-09-12T01:00:00Z');
        $account = $this->account('01990d4a-0000-7000-8000-000000000814', 'offline-user');
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findEnabledPlatformAccounts')->willReturn([$account]);
        $provider = new class () implements TwitCastingLiveStatusProvider {
            public function fetch(string $userId): TwitCastingLiveStatus
            {
                return new TwitCastingLiveStatus($userId, false, null, null, null);
            }
        };
        $existingVideo = new PlatformVideo('01990d4a-0000-7000-8000-000000000817', $account->id, '1234567890', '終了した配信', new DateTimeImmutable('2026-09-12T00:00:00Z'), null, new DateTimeImmutable('2026-09-12T00:00:00Z'), null, null, 'live', new DateTimeImmutable('2026-09-12T00:30:00Z'));
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::once())->method('findLiveByPlatformAccountIds')->with([$account->id])->willReturn([$existingVideo]);
        $videos->expects(self::once())->method('save')->willReturnCallback(static function (PlatformVideo $video) use ($now): string {
            self::assertSame('ended', $video->lifecycleState);
            self::assertEquals($now, $video->actualEndAt);

            return $video->id;
        });
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::once())->method('enqueue')->willReturnCallback(static function (StreamNotificationOutbox $notification): void {
            self::assertSame(StreamNotificationType::Ended, $notification->type);
        });

        $service = new SyncTwitCastingStreams($catalog, $provider, $videos, new EnqueueStreamNotifications(new StreamNotificationSchedule(), $outbox, $this->idGenerator(), $this->clock($now)), $this->idGenerator(), $this->clock($now));

        self::assertSame(1, $service->sync());
    }

    #[Test]
    public function itRejectsAStatusForAnotherAccount(): void
    {
        $account = $this->account('01990d4a-0000-7000-8000-000000000815', 'expected-user');
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findEnabledPlatformAccounts')->willReturn([$account]);
        $provider = new class () implements TwitCastingLiveStatusProvider {
            public function fetch(string $userId): TwitCastingLiveStatus
            {
                return new TwitCastingLiveStatus('another-user', true, '1234567890', 'ライブ配信', new DateTimeImmutable('2026-09-12T00:00:00Z'));
            }
        };
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::never())->method('save');
        $outbox = $this->createStub(StreamNotificationOutboxRepository::class);

        $service = new SyncTwitCastingStreams($catalog, $provider, $videos, new EnqueueStreamNotifications(new StreamNotificationSchedule(), $outbox, $this->idGenerator(), $this->clock(new DateTimeImmutable('2026-09-12T00:00:00Z'))), $this->idGenerator(), $this->clock(new DateTimeImmutable('2026-09-12T00:00:00Z')));

        try {
            $service->sync();
            self::fail('別アカウントの配信状態は拒否される必要があります。');
        } catch (RuntimeException $exception) {
            self::assertSame('TwitCasting配信状態のユーザーIDが一致しません。', $exception->getMessage());
        }
    }

    private function account(string $id, string $externalId): PlatformAccount
    {
        return new PlatformAccount($id, '01990d4a-0000-7000-8000-000000000810', Platform::TwitCasting, $externalId, $externalId, $externalId, $externalId, null, null, null, true, new DateTimeImmutable('2026-09-12T00:00:00Z'));
    }

    private function clock(DateTimeImmutable $now): Clock
    {
        return new class ($now) implements Clock {
            public function __construct(private DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function idGenerator(): IdGenerator
    {
        return new class () implements IdGenerator {
            private int $next = 816;

            public function generate(): string
            {
                return sprintf('01990d4a-0000-7000-8000-%012d', $this->next++);
            }
        };
    }
}
