<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\SyncYouTubeChannelFeed;
use App\Application\Stream\EnqueueStreamNotifications;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\Stream\StreamNotificationSchedule;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedEntry;
use App\Infrastructure\Platform\YouTube\YouTubeChannelFeedProvider;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetails;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SyncYouTubeChannelFeedTest extends TestCase
{
    #[Test]
    public function itDeduplicatesAndSavesVideoDetailsInBatchesOfFifty(): void
    {
        $channelId = 'UCabcdefghijklmnopqrstuv';
        $entries = [];
        for ($index = 0; $index < 51; ++$index) {
            $entries[] = new YouTubeAtomFeedEntry(str_pad((string) $index, 11, 'a'), $channelId);
        }
        $entries[] = $entries[0];
        $feed = new class ($entries) implements YouTubeChannelFeedProvider {
            /** @param list<YouTubeAtomFeedEntry> $entries */
            public function __construct(private array $entries)
            {
            }
            public function fetch(string $channelId): array
            {
                return $this->entries;
            }
        };
        $details = $this->createMock(YouTubeVideoDetailsProvider::class);
        $details->expects(self::exactly(2))->method('fetch')->willReturnCallback(static function (array $ids) use ($channelId): array {
            $result = [];
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    throw new \InvalidArgumentException('動画IDが不正です。');
                }
                $result[] = new YouTubeVideoDetails($id, $channelId, '配信', new DateTimeImmutable('2026-09-09T00:00:00Z'), null, null, null, null, 'upcoming');
            }

            return $result;
        });
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::exactly(51))->method('save')->with(self::isInstanceOf(PlatformVideo::class))->willReturn('01990d4a-0000-7000-8000-000000000704');
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn(new PlatformAccount('01990d4a-0000-7000-8000-000000000701', '01990d4a-0000-7000-8000-000000000702', Platform::YouTube, $channelId, '@channel', '@channel', null, null, null, null, true, new DateTimeImmutable('2026-09-09T00:00:00Z')));

        $service = new SyncYouTubeChannelFeed($catalog, $feed, $details, $videos, new EnqueueStreamNotifications(new StreamNotificationSchedule(), $this->createStub(StreamNotificationOutboxRepository::class), new class () implements IdGenerator {
            public function generate(): string
            {
                return '01990d4a-0000-7000-8000-000000000705';
            }
        }, new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-09T00:00:00Z');
            }
        }), new class () implements IdGenerator {
            public function generate(): string
            {
                return '01990d4a-0000-7000-8000-000000000703';
            }
        }, new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-09T00:00:00Z');
            }
        });

        self::assertSame(51, $service->sync('01990d4a-0000-7000-8000-000000000701'));
    }

    #[Test]
    public function itRejectsDetailsForAVideoThatWasNotInTheFeed(): void
    {
        $channelId = 'UCabcdefghijklmnopqrstuv';
        $catalog = $this->createStub(StreamerCatalogRepository::class);
        $catalog->method('findPlatformAccountById')->willReturn(new PlatformAccount('01990d4a-0000-7000-8000-000000000701', '01990d4a-0000-7000-8000-000000000702', Platform::YouTube, $channelId, '@channel', '@channel', null, null, null, null, true, new DateTimeImmutable('2026-09-09T00:00:00Z')));
        $feed = new class ($channelId) implements YouTubeChannelFeedProvider {
            public function __construct(private string $channelId)
            {
            }

            public function fetch(string $channelId): array
            {
                return [new YouTubeAtomFeedEntry('aaaaaaaaaaa', $this->channelId)];
            }
        };
        $details = new class ($channelId) implements YouTubeVideoDetailsProvider {
            public function __construct(private string $channelId)
            {
            }

            public function fetch(array $videoIds): array
            {
                return [new YouTubeVideoDetails('bbbbbbbbbbb', $this->channelId, '対象外動画', new DateTimeImmutable('2026-09-09T00:00:00Z'), null, null, null, null, 'none')];
            }
        };
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::never())->method('save');
        $ids = new class () implements IdGenerator {
            public function generate(): string
            {
                return '01990d4a-0000-7000-8000-000000000703';
            }
        };
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-09T00:00:00Z');
            }
        };
        $service = new SyncYouTubeChannelFeed($catalog, $feed, $details, $videos, new EnqueueStreamNotifications(new StreamNotificationSchedule(), $this->createStub(StreamNotificationOutboxRepository::class), $ids, $clock), $ids, $clock);

        $this->expectExceptionObject(new \InvalidArgumentException('YouTube動画詳細の動画IDが要求内容に含まれません。'));
        $service->sync('01990d4a-0000-7000-8000-000000000701');
    }
}
