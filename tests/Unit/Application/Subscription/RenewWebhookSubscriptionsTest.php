<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\RenewWebhookSubscriptions;
use App\Application\Subscription\RenewWebhookSubscriptionsInput;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\UnsupportedPlatform;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionRequestDispatcher;
use App\Domain\Subscription\WebhookSubscriptionRequestFailed;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\IntegerRandomizer;
use App\Domain\System\LeaseTokenGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RenewWebhookSubscriptionsTest extends TestCase
{
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000501';
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000502';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000503';
    private const LEASE_TOKEN = '00112233445566778899aabbccddeeff';

    private WebhookSubscriptionRepository&MockObject $subscriptionRepository;
    private StreamerCatalogRepository&Stub $streamerCatalogRepository;
    private WebhookSubscriptionRequestDispatcher&Stub $requestDispatcher;
    private IntegerRandomizer&Stub $integerRandomizer;
    private Clock&Stub $clock;

    protected function setUp(): void
    {
        $this->subscriptionRepository = $this->createMock(WebhookSubscriptionRepository::class);
        $this->streamerCatalogRepository = $this->createStub(StreamerCatalogRepository::class);
        $this->requestDispatcher = $this->createStub(WebhookSubscriptionRequestDispatcher::class);
        $this->integerRandomizer = $this->createStub(IntegerRandomizer::class);
        $this->clock = $this->createStub(Clock::class);
        $this->clock
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-05 00:00:00+00:00'));
    }

    #[Test]
    public function itSchedulesAcceptedRequestsForVerificationWithoutAFailure(): void
    {
        $subscription = $this->claimedSubscription();
        $account = $this->account();
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository = $this->createMock(StreamerCatalogRepository::class);
        $this->streamerCatalogRepository
            ->expects($this->once())
            ->method('findPlatformAccountById')
            ->with(self::ACCOUNT_ID)
            ->willReturn($account);
        $this->requestDispatcher = $this->createMock(WebhookSubscriptionRequestDispatcher::class);
        $this->requestDispatcher
            ->expects($this->once())
            ->method('requestSubscription')
            ->with($account, $subscription);
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('saveClaimResult')
            ->with($this->callback(static fn (WebhookSubscription $result): bool => (
                $result->failureCount === 0
                && $result->lastErrorCode === null
                && $result->renewAfter?->format('Y-m-d H:i:s') === '2026-09-05 00:05:00'
            )))
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->claimedCount);
        self::assertSame(1, $result->acceptedCount);
        self::assertSame(0, $result->retryScheduledCount);
        self::assertSame(0, $result->permanentlyFailedCount);
        self::assertSame(0, $result->releasedCount);
        self::assertSame(0, $result->staleResultCount);
    }

    #[Test]
    public function itSchedulesARetryWithExponentialBackoffAndJitter(): void
    {
        $subscription = $this->claimedSubscription(failureCount: 0);
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository->method('findPlatformAccountById')->willReturn($this->account());
        $this->requestDispatcher
            ->method('requestSubscription')
            ->willThrowException(new WebhookSubscriptionRequestFailed(
                Platform::YouTube,
                'transport_error',
                true,
            ));
        $this->integerRandomizer = $this->createMock(IntegerRandomizer::class);
        $this->integerRandomizer
            ->expects($this->once())
            ->method('between')
            ->with(-12, 12)
            ->willReturn(12);
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('saveClaimResult')
            ->with($this->callback(static fn (WebhookSubscription $result): bool => (
                $result->failureCount === 1
                && $result->lastErrorCode === 'transport_error'
                && $result->renewAfter?->format('Y-m-d H:i:s') === '2026-09-05 00:01:12'
            )))
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->retryScheduledCount);
    }

    #[Test]
    public function itStopsRetryingAtTheMaximumAttemptCount(): void
    {
        $subscription = $this->claimedSubscription(failureCount: 7);
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository->method('findPlatformAccountById')->willReturn($this->account());
        $this->requestDispatcher
            ->method('requestSubscription')
            ->willThrowException(new WebhookSubscriptionRequestFailed(
                Platform::YouTube,
                'http_429',
                true,
            ));
        $this->integerRandomizer = $this->createMock(IntegerRandomizer::class);
        $this->integerRandomizer->expects($this->never())->method('between');
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('saveClaimResult')
            ->with($this->callback(static fn (WebhookSubscription $result): bool => (
                $result->status === WebhookSubscriptionStatus::Error
                && $result->failureCount === 8
                && $result->renewAfter === null
                && $result->lastErrorCode === 'http_429'
            )))
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->permanentlyFailedCount);
    }

    #[Test]
    public function itFailsPermanentlyWhenThePlatformAccountIsUnavailable(): void
    {
        $subscription = $this->claimedSubscription();
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository
            ->method('findPlatformAccountById')
            ->willReturn(null);
        $this->requestDispatcher = $this->createMock(WebhookSubscriptionRequestDispatcher::class);
        $this->requestDispatcher->expects($this->never())->method('requestSubscription');
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('saveClaimResult')
            ->with($this->callback(static fn (WebhookSubscription $result): bool => (
                $result->status === WebhookSubscriptionStatus::Error
                && $result->lastErrorCode === 'platform_account_unavailable'
            )))
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->permanentlyFailedCount);
    }

    #[Test]
    public function itFailsPermanentlyWhenThePlatformHasNoRequester(): void
    {
        $subscription = $this->claimedSubscription();
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository->method('findPlatformAccountById')->willReturn($this->account());
        $this->requestDispatcher
            ->method('requestSubscription')
            ->willThrowException(new UnsupportedPlatform(Platform::YouTube));
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('saveClaimResult')
            ->with($this->callback(static fn (WebhookSubscription $result): bool => (
                $result->status === WebhookSubscriptionStatus::Error
                && $result->lastErrorCode === 'unsupported_platform'
            )))
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->permanentlyFailedCount);
    }

    #[Test]
    public function itCountsAResultRejectedByAStaleLeaseOwner(): void
    {
        $subscription = $this->claimedSubscription();
        $this->expectSingleClaim($subscription);
        $this->streamerCatalogRepository->method('findPlatformAccountById')->willReturn($this->account());
        $this->subscriptionRepository->method('saveClaimResult')->willReturn(false);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 1));

        self::assertSame(1, $result->claimedCount);
        self::assertSame(1, $result->staleResultCount);
        self::assertSame(0, $result->acceptedCount);
    }

    #[Test]
    public function itDoesNotClaimNewWorkAfterTheRuntimeLimit(): void
    {
        $this->clock = $this->createStub(Clock::class);
        $this->clock
            ->method('now')
            ->willReturnOnConsecutiveCalls(
                new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
                new DateTimeImmutable('2026-09-05 00:00:45+00:00'),
            );
        $this->subscriptionRepository->expects($this->never())->method('claimDue');

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput());

        self::assertSame(0, $result->claimedCount);
    }

    #[Test]
    public function itReleasesClaimedWorkThatCannotStartWithinTheRuntimeLimit(): void
    {
        $firstSubscription = $this->claimedSubscription();
        $secondSubscription = new WebhookSubscription(
            '01990d4a-0000-7000-8000-000000000504',
            self::ACCOUNT_ID,
            'channel.update',
            null,
            WebhookSubscriptionStatus::Pending,
            null,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            0,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-09-05 00:02:00+00:00'),
            null,
        );
        $this->clock = $this->createStub(Clock::class);
        $this->clock
            ->method('now')
            ->willReturnOnConsecutiveCalls(
                new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
                new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
                new DateTimeImmutable('2026-09-05 00:00:45+00:00'),
            );
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('claimDue')
            ->with(2, self::LEASE_TOKEN, 120)
            ->willReturn([$firstSubscription, $secondSubscription]);
        $this->subscriptionRepository
            ->expects($this->exactly(2))
            ->method('releaseClaim')
            ->willReturn(true);

        $result = $this->service()->renew(new RenewWebhookSubscriptionsInput(batchSize: 2));

        self::assertSame(2, $result->claimedCount);
        self::assertSame(2, $result->releasedCount);
        self::assertSame(0, $result->acceptedCount);
        self::assertSame(0, $result->staleResultCount);
    }

    private function expectSingleClaim(WebhookSubscription $subscription): void
    {
        $this->subscriptionRepository
            ->expects($this->once())
            ->method('claimDue')
            ->with(1, self::LEASE_TOKEN, 120)
            ->willReturn([$subscription]);
    }

    private function service(): RenewWebhookSubscriptions
    {
        $leaseTokenGenerator = $this->createStub(LeaseTokenGenerator::class);
        $leaseTokenGenerator->method('generate')->willReturn(self::LEASE_TOKEN);

        return new RenewWebhookSubscriptions(
            $this->subscriptionRepository,
            $this->streamerCatalogRepository,
            $this->requestDispatcher,
            $leaseTokenGenerator,
            $this->integerRandomizer,
            $this->clock,
        );
    }

    private function account(): PlatformAccount
    {
        return new PlatformAccount(
            self::ACCOUNT_ID,
            self::STREAMER_ID,
            Platform::YouTube,
            'UC0123456789012345678901',
            '@channel',
            '@channel',
            null,
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
        );
    }

    private function claimedSubscription(int $failureCount = 0): WebhookSubscription
    {
        return new WebhookSubscription(
            self::SUBSCRIPTION_ID,
            self::ACCOUNT_ID,
            'channel.feed',
            null,
            WebhookSubscriptionStatus::Pending,
            null,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            $failureCount,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-09-05 00:02:00+00:00'),
            null,
        );
    }
}
