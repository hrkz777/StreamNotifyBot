<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationType;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrinePlatformVideoRepository;
use App\Infrastructure\Persistence\DoctrineStreamNotificationOutboxRepository;
use App\Infrastructure\Persistence\DoctrineStreamerCatalogRepository;
use App\Infrastructure\Persistence\DoctrineWebhookSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineStreamNotificationOutboxRepositoryTest extends KernelTestCase
{
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000601';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000602';
    private const VIDEO_ID = '01990d4a-0000-7000-8000-000000000603';
    private const OUTBOX_ID = '01990d4a-0000-7000-8000-000000000604';
    private const AGENCY_ID = '01990d4a-0000-7000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $clock = $this->clock();
        $subscriptions = new DoctrineWebhookSubscriptionRepository($this->connection, $clock);
        (new DoctrineStreamerCatalogRepository($this->connection, $clock, $subscriptions))->register(
            new Streamer(self::STREAMER_ID, self::AGENCY_ID, SupportedLanguage::Japanese, null, true, [new StreamerName(SupportedLanguage::Japanese, 'テスト配信者')]),
            new PlatformAccount(self::ACCOUNT_ID, self::STREAMER_ID, Platform::YouTube, 'UCabcdefghijklmnopqrstuv', '@channel', '@channel', 'テストチャンネル', 'https://www.youtube.com/@channel', null, null, true, new DateTimeImmutable('2026-09-08 00:00:00+00:00')),
        );
        (new DoctrinePlatformVideoRepository($this->connection))->save(new PlatformVideo(
            self::VIDEO_ID,
            self::ACCOUNT_ID,
            'abcdefghijk',
            'テスト配信',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            null,
            null,
            null,
            null,
            'none',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itClaimsAndMarksPendingNotificationsAsSent(): void
    {
        $repository = new DoctrineStreamNotificationOutboxRepository($this->connection);
        $repository->enqueue(new StreamNotificationOutbox(self::OUTBOX_ID, self::VIDEO_ID, StreamNotificationType::VideoPublished, str_repeat('a', 64), new DateTimeImmutable('2026-09-08 00:00:00.123456+00:00')));

        $claimed = $repository->claimPending(1, '00112233445566778899aabbccddeeff', 120);

        self::assertCount(1, $claimed);
        self::assertSame(self::OUTBOX_ID, $claimed[0]->notification->id);
        self::assertTrue($repository->markSent($claimed[0]));
        self::assertSame([], $repository->claimPending(1, 'ffeeddccbbaa99887766554433221100', 120));
        $sent = $repository->findSentSince(new DateTimeImmutable('2026-09-08 00:00:00+00:00'), 10);
        self::assertCount(1, $sent);
        self::assertSame(self::VIDEO_ID, $sent[0]->platformVideoId);
        self::assertSame(StreamNotificationType::VideoPublished, $sent[0]->type);
        self::assertSame([], $repository->findSentSince(new DateTimeImmutable('2999-01-01 00:00:00+00:00'), 10));
    }

    #[Test]
    public function itFindsPersistedPlatformVideosById(): void
    {
        $video = (new DoctrinePlatformVideoRepository($this->connection))->findById(self::VIDEO_ID);

        self::assertNotNull($video);
        self::assertSame(self::VIDEO_ID, $video->id);
        self::assertSame('abcdefghijk', $video->externalVideoId);
        self::assertSame('テスト配信', $video->title);
        self::assertNull($video->scheduledStartAt);
    }

    #[Test]
    public function itFindsOnlyLiveVideosForTheRequestedPlatformAccounts(): void
    {
        $repository = new DoctrinePlatformVideoRepository($this->connection);
        $repository->save(new PlatformVideo(
            self::VIDEO_ID,
            self::ACCOUNT_ID,
            'abcdefghijk',
            'ライブ配信',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            null,
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            null,
            null,
            'live',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        ));

        $videos = $repository->findLiveByPlatformAccountIds([self::ACCOUNT_ID]);

        self::assertCount(1, $videos);
        self::assertSame(self::VIDEO_ID, $videos[0]->id);
        self::assertSame('live', $videos[0]->lifecycleState);
        self::assertSame([], $repository->findLiveByPlatformAccountIds([]));
    }

    #[Test]
    public function itFindsFutureUpcomingVideosInScheduledOrder(): void
    {
        $repository = new DoctrinePlatformVideoRepository($this->connection);
        $repository->save(new PlatformVideo(
            '01990d4a-0000-7000-8000-000000000305',
            self::ACCOUNT_ID,
            'upcoming-later',
            '後の予定配信',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-09 02:00:00+00:00'),
            null,
            null,
            null,
            'upcoming',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        ));
        $repository->save(new PlatformVideo(
            '01990d4a-0000-7000-8000-000000000304',
            self::ACCOUNT_ID,
            'upcoming-earlier',
            '先の予定配信',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-09 01:00:00+00:00'),
            null,
            null,
            null,
            'upcoming',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
        ));

        $videos = $repository->findUpcomingByPlatformAccountIds([self::ACCOUNT_ID], new DateTimeImmutable('2026-09-09 00:00:00+00:00'), 1);

        self::assertCount(1, $videos);
        self::assertSame('upcoming-earlier', $videos[0]->externalVideoId);
        self::assertSame([], $repository->findUpcomingByPlatformAccountIds([], new DateTimeImmutable('2026-09-09 00:00:00+00:00'), 5));
    }

    private function clock(): Clock
    {
        return new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-08 00:00:00', new DateTimeZone('UTC'));
            }
        };
    }
}
