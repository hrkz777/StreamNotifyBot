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
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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

    #[Test]
    public function itClaimsOnlyTheConfiguredNumberOfDueSubscriptions(): void
    {
        $repository = $this->repository();
        $repository->add($this->pendingSubscription(self::SUBSCRIPTION_ID, 'stream.online'));
        $repository->add($this->pendingSubscription(
            '01990d4a-0000-7000-8000-000000000302',
            'stream.offline',
        ));
        $repository->add($this->pendingSubscription(
            '01990d4a-0000-7000-8000-000000000303',
            'channel.update',
        ));

        $firstClaim = $repository->claimDue(2, '00112233445566778899aabbccddeeff', 120);
        $secondClaim = $repository->claimDue(2, 'ffeeddccbbaa99887766554433221100', 120);

        self::assertCount(2, $firstClaim);
        self::assertCount(1, $secondClaim);
        self::assertSame('00112233445566778899aabbccddeeff', $firstClaim[0]->processingLeaseToken);
        self::assertNotNull($firstClaim[0]->lastAttemptedAt);
        self::assertNotSame($firstClaim[0]->id, $secondClaim[0]->id);
    }

    #[Test]
    public function itRejectsReusingAnActiveLeaseToken(): void
    {
        $repository = $this->repository();
        $repository->add($this->pendingSubscription(self::SUBSCRIPTION_ID, 'stream.online'));
        $repository->add($this->pendingSubscription(
            '01990d4a-0000-7000-8000-000000000302',
            'stream.offline',
        ));
        $leaseToken = '00112233445566778899aabbccddeeff';
        $repository->claimDue(1, $leaseToken, 120);

        $this->expectException(InvalidArgumentException::class);

        $repository->claimDue(1, $leaseToken, 120);
    }

    #[Test]
    public function itSavesAClaimResultAndReleasesTheLease(): void
    {
        $repository = $this->repository();
        $repository->add($this->pendingSubscription(self::SUBSCRIPTION_ID, 'stream.online'));
        $leaseToken = '00112233445566778899aabbccddeeff';
        $claimed = $repository->claimDue(1, $leaseToken, 120);
        self::assertCount(1, $claimed);

        $result = $claimed[0]->activate(
            'new-external-subscription-id',
            new DateTimeImmutable('2030-09-03 00:00:00+00:00'),
            new DateTimeImmutable('2030-09-02 12:00:00+00:00'),
        );

        self::assertTrue($repository->saveClaimResult($result));
        $stored = $repository->findById(self::SUBSCRIPTION_ID);
        self::assertNotNull($stored);
        self::assertSame(WebhookSubscriptionStatus::Active, $stored->status);
        self::assertSame('new-external-subscription-id', $stored->externalSubscriptionId);
        self::assertNull($stored->processingLeaseToken);
        self::assertSame(0, $stored->failureCount);
    }

    #[Test]
    public function itReleasesAnUnprocessedClaimWithoutChangingItsSchedule(): void
    {
        $repository = $this->repository();
        $repository->add($this->pendingSubscription(self::SUBSCRIPTION_ID, 'stream.online'));
        $claimed = $repository->claimDue(1, '00112233445566778899aabbccddeeff', 120);
        self::assertCount(1, $claimed);
        $renewAfter = $claimed[0]->renewAfter;
        $lastAttemptedAt = $claimed[0]->lastAttemptedAt;

        self::assertTrue($repository->releaseClaim($claimed[0]));

        $stored = $repository->findById(self::SUBSCRIPTION_ID);
        self::assertNotNull($stored);
        self::assertNull($stored->processingLeaseToken);
        self::assertNull($stored->processingLeaseUntil);
        self::assertEquals($renewAfter, $stored->renewAfter);
        self::assertEquals($lastAttemptedAt, $stored->lastAttemptedAt);
    }

    #[Test]
    public function itDiscardsAResultFromAStaleLeaseOwner(): void
    {
        $repository = $this->repository();
        $repository->add($this->pendingSubscription(self::SUBSCRIPTION_ID, 'stream.online'));
        $oldLeaseToken = '00112233445566778899aabbccddeeff';
        $claimed = $repository->claimDue(1, $oldLeaseToken, 120);
        self::assertCount(1, $claimed);

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE webhook_subscriptions
                SET processing_lease_token = ?, processing_lease_until = '2030-09-02 00:02:00.000000'
                WHERE id = ?
                SQL,
            [
                hex2bin('ffeeddccbbaa99887766554433221100'),
                Uuid::fromString(self::SUBSCRIPTION_ID)->toBinary(),
            ],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        $result = $claimed[0]->failPermanently('HTTP_401');

        self::assertFalse($repository->saveClaimResult($result));
        self::assertSame(
            'ffeeddccbbaa99887766554433221100',
            $repository->findById(self::SUBSCRIPTION_ID)?->processingLeaseToken,
        );
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

    private function pendingSubscription(string $id, string $subscriptionType): WebhookSubscription
    {
        return WebhookSubscription::pending(
            $id,
            self::ACCOUNT_ID,
            $subscriptionType,
            new DateTimeImmutable('2000-01-01 00:00:00+00:00'),
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
