<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Stream\EnqueueStreamNotifications;
use App\Application\Subscription\SyncTwitchStreams;
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
use App\Infrastructure\Platform\Twitch\TwitchStreamStatus;
use App\Infrastructure\Platform\Twitch\TwitchStreamStatusProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SyncTwitchStreamsTest extends TestCase
{
    #[Test]
    public function itChunksAccountsAndOnlySavesLiveStreamsForKnownAccounts(): void
    {
        $now = new DateTimeImmutable('2026-09-12T00:00:00Z');
        $accounts = [];
        for ($index = 0; $index < 101; ++$index) {
            $accounts[] = $this->account($index);
        }
        $catalog = $this->createMock(StreamerCatalogRepository::class);
        $catalog->expects(self::once())->method('findEnabledPlatformAccounts')->with(Platform::Twitch)->willReturn($accounts);
        $provider = $this->createMock(TwitchStreamStatusProvider::class);
        $batch = 0;
        $provider->expects(self::exactly(2))->method('fetch')->willReturnCallback(static function (array $userIds) use (&$batch, $now): array {
            ++$batch;
            self::assertCount($batch === 1 ? 100 : 1, $userIds);

            if ($batch === 1) {
                self::assertContains('user-000', $userIds);

                return [
                    new TwitchStreamStatus('user-000', true, 'stream-123', 'ライブ配信', $now, 'https://example.test/thumbnail.jpg'),
                    new TwitchStreamStatus('unknown-user', true, 'stream-999', '対象外', $now, null),
                ];
            }

            self::assertSame(['user-100'], $userIds);

            return [new TwitchStreamStatus($userIds[0], false, null, null, null, null)];
        });
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::once())->method('save')->willReturnCallback(static function (PlatformVideo $video) use ($accounts, $now): string {
            self::assertSame($accounts[0]->id, $video->platformAccountId);
            self::assertSame('stream-123', $video->externalVideoId);
            self::assertSame('ライブ配信', $video->title);
            self::assertEquals($now, $video->actualStartAt);
            self::assertSame('https://example.test/thumbnail.jpg', $video->thumbnailUrl);

            return '01990d4a-0000-7000-8000-000000001001';
        });
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::once())->method('enqueue')->willReturnCallback(static function (StreamNotificationOutbox $notification) use ($now): void {
            self::assertSame('01990d4a-0000-7000-8000-000000001001', $notification->platformVideoId);
            self::assertSame(StreamNotificationType::Started, $notification->type);
            self::assertSame($now, $notification->occurredAt);
        });

        $service = new SyncTwitchStreams(
            $catalog,
            $provider,
            $videos,
            new EnqueueStreamNotifications(new StreamNotificationSchedule(), $outbox, $this->idGenerator(), $this->clock($now)),
            $this->idGenerator(),
            $this->clock($now),
        );

        self::assertSame(1, $service->sync());
    }

    private function account(int $index): PlatformAccount
    {
        $id = sprintf('01990d4a-0000-7000-8000-%012d', 900 + $index);
        $externalId = sprintf('user-%03d', $index);

        return new PlatformAccount($id, '01990d4a-0000-7000-8000-000000000899', Platform::Twitch, $externalId, $externalId, $externalId, $externalId, null, null, null, true, new DateTimeImmutable('2026-09-12T00:00:00Z'));
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
            private int $next = 1002;

            public function generate(): string
            {
                return sprintf('01990d4a-0000-7000-8000-%012d', $this->next++);
            }
        };
    }
}
