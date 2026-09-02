<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrineStreamerCatalogRepository;
use App\Infrastructure\Persistence\DoctrineWebhookSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineWebhookSubscriptionRepositoryTest extends KernelTestCase
{
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000201';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000211';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000301';
    private const INDEPENDENT_AGENCY_ID = '01990d4a-0000-7000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->registerAccount();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itStoresAndLoadsAWebhookSubscription(): void
    {
        $repository = $this->repository();
        $repository->add($this->subscription());

        $stored = $repository->findByAccountAndType(self::ACCOUNT_ID, 'stream.online');

        self::assertNotNull($stored);
        self::assertSame(self::SUBSCRIPTION_ID, $stored->id);
        self::assertSame('external-subscription-id', $stored->externalSubscriptionId);
        self::assertSame(WebhookSubscriptionStatus::Active, $stored->status);
        self::assertSame('00112233445566778899aabbccddeeff', $stored->processingLeaseToken);
        self::assertSame('2026-09-03 00:00:00.123456', $stored->expiresAt?->format('Y-m-d H:i:s.u'));
        self::assertSame(self::SUBSCRIPTION_ID, $repository->findById(self::SUBSCRIPTION_ID)?->id);
    }

    #[Test]
    public function itRejectsADuplicateSubscriptionTypeForAnAccount(): void
    {
        $repository = $this->repository();
        $repository->add($this->subscription());

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->add(new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000302',
            self::ACCOUNT_ID,
            'stream.online',
            'another-external-id',
            WebhookSubscriptionStatus::Pending,
            null,
            null,
            null,
            0,
            null,
            null,
            null,
        ));
    }

    #[Test]
    public function itStoresDifferentSubscriptionTypesForAnAccount(): void
    {
        $repository = $this->repository();
        $repository->add($this->subscription());
        $repository->add(new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000302',
            self::ACCOUNT_ID,
            'stream.offline',
            'offline-subscription-id',
            WebhookSubscriptionStatus::Active,
            null,
            null,
            null,
            0,
            null,
            null,
            null,
        ));

        self::assertNotNull($repository->findByAccountAndType(self::ACCOUNT_ID, 'stream.online'));
        self::assertNotNull($repository->findByAccountAndType(self::ACCOUNT_ID, 'stream.offline'));
    }

    private function registerAccount(): void
    {
        $clock = $this->clock();
        $catalog = new DoctrineStreamerCatalogRepository(
            $this->connection,
            $clock,
            new DoctrineWebhookSubscriptionRepository($this->connection, $clock),
        );
        $catalog->register(
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
                Platform::Twitch,
                '123456789',
                'test_streamer',
                'test_streamer',
                'Test Streamer',
                'https://www.twitch.tv/test_streamer',
                null,
                null,
                true,
                new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            ),
        );
    }

    private function subscription(): WebhookSubscription
    {
        return new WebhookSubscription(
            self::SUBSCRIPTION_ID,
            self::ACCOUNT_ID,
            'stream.online',
            'external-subscription-id',
            WebhookSubscriptionStatus::Active,
            new DateTimeImmutable('2026-09-03 00:00:00.123456+00:00'),
            new DateTimeImmutable('2026-09-02 12:00:00.123456+00:00'),
            new DateTimeImmutable('2026-09-02 00:00:00.123456+00:00'),
            1,
            '00112233445566778899aabbccddeeff',
            new DateTimeImmutable('2026-09-02 00:02:00.123456+00:00'),
            'HTTP_503',
        );
    }

    private function repository(): DoctrineWebhookSubscriptionRepository
    {
        return new DoctrineWebhookSubscriptionRepository($this->connection, $this->clock());
    }

    private function clock(): Clock
    {
        return new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-02 00:00:00.000000', new DateTimeZone('UTC'));
            }
        };
    }
}
