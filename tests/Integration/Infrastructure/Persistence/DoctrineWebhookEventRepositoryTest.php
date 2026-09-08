<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrineStreamerCatalogRepository;
use App\Infrastructure\Persistence\DoctrineWebhookEventRepository;
use App\Infrastructure\Persistence\DoctrineWebhookSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineWebhookEventRepositoryTest extends KernelTestCase
{
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000501';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000502';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000503';
    private const EVENT_ID = '01990d4a-0000-7000-8000-000000000504';
    private const INDEPENDENT_AGENCY_ID = '01990d4a-0000-7000-8000-000000000001';

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
            new Streamer(
                self::STREAMER_ID,
                self::INDEPENDENT_AGENCY_ID,
                SupportedLanguage::Japanese,
                null,
                true,
                [new StreamerName(SupportedLanguage::Japanese, 'テスト配信者')],
            ),
            new PlatformAccount(
                self::ACCOUNT_ID,
                self::STREAMER_ID,
                Platform::YouTube,
                'UCabcdefghijklmnopqrstuv',
                '@channel',
                '@channel',
                'テストチャンネル',
                'https://www.youtube.com/@channel',
                null,
                null,
                true,
                new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            ),
        );
        $subscriptions->add(WebhookSubscription::pending(
            self::SUBSCRIPTION_ID,
            self::ACCOUNT_ID,
            'channel.feed',
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
    public function itClaimsAndMarksPendingEventsAsProcessed(): void
    {
        $repository = new DoctrineWebhookEventRepository($this->connection);
        $event = new WebhookEvent(
            self::EVENT_ID,
            self::SUBSCRIPTION_ID,
            '<feed>通知</feed>',
            new DateTimeImmutable('2026-09-08 00:00:00.123456+00:00'),
        );

        self::assertTrue($repository->record($event));
        self::assertFalse($repository->record(new WebhookEvent(
            '01990d4a-0000-7000-8000-000000000505',
            self::SUBSCRIPTION_ID,
            '<feed>通知</feed>',
            new DateTimeImmutable('2026-09-08 00:00:01+00:00'),
        )));
        $claimed = $repository->claimPending(1, '00112233445566778899aabbccddeeff', 120);

        self::assertCount(1, $claimed);
        self::assertSame(self::EVENT_ID, $claimed[0]->event->id);
        self::assertTrue($repository->markProcessed($claimed[0]));
        self::assertSame([], $repository->claimPending(1, 'ffeeddccbbaa99887766554433221100', 120));
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
