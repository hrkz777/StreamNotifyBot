<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrineStreamerCatalogRepository;
use App\Infrastructure\Persistence\DoctrineWebhookSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineStreamerCatalogRepositoryTest extends KernelTestCase
{
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000201';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000211';
    private const INDEPENDENT_AGENCY_ID = '01990d4a-0000-7000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itRegistersAndLoadsAStreamerWithAnInitialAccountAtomically(): void
    {
        $repository = $this->repository();
        $repository->register($this->streamer(), $this->account());

        $storedStreamer = $repository->findStreamerById(self::STREAMER_ID);
        self::assertNotNull($storedStreamer);
        self::assertSame('#12ABEF', $storedStreamer->colorCode);
        self::assertSame('テスト配信者', $storedStreamer->nameFor(SupportedLanguage::Japanese)->name);
        self::assertSame('Test Streamer', $storedStreamer->nameFor(SupportedLanguage::English)->name);

        $storedAccount = $repository->findPlatformAccountByExternalId(Platform::YouTube, 'UC_TEST_ACCOUNT');
        self::assertNotNull($storedAccount);
        self::assertSame(self::ACCOUNT_ID, $storedAccount->id);
        self::assertSame('@test_streamer', $storedAccount->displayId);
        self::assertSame('Test Streamer Channel', $storedAccount->name);
        self::assertSame('2026-09-01 15:00:00.123456', $storedAccount->resolvedAt->format('Y-m-d H:i:s.u'));

        self::assertSame(self::ACCOUNT_ID, $repository->findPlatformAccountById(self::ACCOUNT_ID)?->id);
    }

    #[Test]
    public function itRegistersInitialWebhookSubscriptionsInTheSameTransaction(): void
    {
        $repository = $this->repository();
        $repository->register(
            $this->streamer(),
            $this->account(),
            [WebhookSubscription::pending(
                '01990d4a-0000-7000-8000-000000000301',
                self::ACCOUNT_ID,
                'channel.feed',
                new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            )],
        );

        $stored = $this->subscriptionRepository()->findByAccountAndType(self::ACCOUNT_ID, 'channel.feed');

        self::assertNotNull($stored);
        self::assertSame('01990d4a-0000-7000-8000-000000000301', $stored->id);
        self::assertSame('2026-09-02 00:00:00', $stored->renewAfter?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itRollsBackTheRegistrationWhenAnInitialSubscriptionCannotBeStored(): void
    {
        try {
            $this->repository()->register(
                $this->streamer(),
                $this->account(),
                [
                    WebhookSubscription::pending(
                        '01990d4a-0000-7000-8000-000000000301',
                        self::ACCOUNT_ID,
                        'channel.feed',
                        new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
                    ),
                    WebhookSubscription::pending(
                        '01990d4a-0000-7000-8000-000000000302',
                        self::ACCOUNT_ID,
                        'channel.feed',
                        new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
                    ),
                ],
            );
            self::fail('重複する購読種別の保存は失敗する必要があります。');
        } catch (UniqueConstraintViolationException) {
            self::assertNull($this->repository()->findStreamerById(self::STREAMER_ID));
            self::assertNull($this->repository()->findPlatformAccountById(self::ACCOUNT_ID));
        }
    }

    #[Test]
    public function itAddsAnotherPlatformAccountToTheStreamer(): void
    {
        $repository = $this->repository();
        $repository->register($this->streamer(), $this->account());
        $twitchAccount = new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000212',
            self::STREAMER_ID,
            Platform::Twitch,
            'test_streamer',
            'test_streamer',
            'test_streamer',
            'Test Streamer',
            'https://www.twitch.tv/test_streamer',
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-01 16:00:00.000000+00:00'),
        );

        $repository->addPlatformAccount($twitchAccount);

        self::assertSame(
            $twitchAccount->id,
            $repository->findPlatformAccountByExternalId(Platform::Twitch, 'test_streamer')?->id,
        );
    }

    #[Test]
    public function itRejectsADuplicateExternalAccount(): void
    {
        $repository = $this->repository();
        $repository->register($this->streamer(), $this->account());

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->addPlatformAccount(new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000212',
            self::STREAMER_ID,
            Platform::YouTube,
            'UC_TEST_ACCOUNT',
            '@duplicate',
            '@duplicate',
            'Duplicate',
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-01 15:00:00.123456+00:00'),
        ));
    }

    #[Test]
    public function itRejectsAnInitialAccountBelongingToAnotherStreamer(): void
    {
        $account = new PlatformAccount(
            self::ACCOUNT_ID,
            '01990d4a-0000-7000-8000-000000000299',
            Platform::YouTube,
            'UC_OTHER_ACCOUNT',
            '@other',
            '@other',
            'Other Channel',
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-01 15:00:00.123456+00:00'),
        );

        $this->expectException(InvalidArgumentException::class);

        $this->repository()->register($this->streamer(), $account);
    }

    private function streamer(): Streamer
    {
        return new Streamer(
            self::STREAMER_ID,
            self::INDEPENDENT_AGENCY_ID,
            SupportedLanguage::Japanese,
            '#12abef',
            true,
            [
                new StreamerName(SupportedLanguage::Japanese, 'テスト配信者'),
                new StreamerName(SupportedLanguage::English, 'Test Streamer'),
            ],
        );
    }

    private function account(): PlatformAccount
    {
        return new PlatformAccount(
            self::ACCOUNT_ID,
            self::STREAMER_ID,
            Platform::YouTube,
            'UC_TEST_ACCOUNT',
            '@test_streamer',
            '@test_streamer',
            'Test Streamer Channel',
            'https://www.youtube.com/@test_streamer',
            'https://example.com/icon.png',
            null,
            true,
            new DateTimeImmutable('2026-09-01 15:00:00.123456+00:00'),
            new DateTimeImmutable('2026-09-01 15:00:00.123456+00:00'),
            new DateTimeImmutable('2026-09-15 15:00:00.123456+00:00'),
        );
    }

    private function repository(): DoctrineStreamerCatalogRepository
    {
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-02 00:00:00.000000', new DateTimeZone('UTC'));
            }
        };

        return new DoctrineStreamerCatalogRepository(
            $this->connection,
            $clock,
            new DoctrineWebhookSubscriptionRepository($this->connection, $clock),
        );
    }

    private function subscriptionRepository(): DoctrineWebhookSubscriptionRepository
    {
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-02 00:00:00.000000', new DateTimeZone('UTC'));
            }
        };

        return new DoctrineWebhookSubscriptionRepository($this->connection, $clock);
    }
}
