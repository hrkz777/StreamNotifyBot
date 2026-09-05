<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\UnsupportedPlatform;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRequester;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Infrastructure\Platform\WebhookSubscriptionRequesterRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookSubscriptionRequesterRegistryTest extends TestCase
{
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000401';
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000402';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000403';

    #[Test]
    public function itDelegatesToTheRequesterForTheAccountsPlatform(): void
    {
        $account = $this->account(Platform::YouTube);
        $subscription = $this->subscription();
        $requester = $this->createMock(WebhookSubscriptionRequester::class);
        $requester->method('platform')->willReturn(Platform::YouTube);
        $requester
            ->expects($this->once())
            ->method('requestSubscription')
            ->with($account, $subscription);

        (new WebhookSubscriptionRequesterRegistry([$requester]))
            ->requestSubscription($account, $subscription);
    }

    #[Test]
    public function itRejectsDuplicateRequesters(): void
    {
        $first = $this->createStub(WebhookSubscriptionRequester::class);
        $first->method('platform')->willReturn(Platform::YouTube);
        $second = $this->createStub(WebhookSubscriptionRequester::class);
        $second->method('platform')->willReturn(Platform::YouTube);

        $this->expectException(InvalidArgumentException::class);

        new WebhookSubscriptionRequesterRegistry([$first, $second]);
    }

    #[Test]
    public function itRejectsAPlatformWithoutARequester(): void
    {
        $this->expectException(UnsupportedPlatform::class);

        (new WebhookSubscriptionRequesterRegistry([]))
            ->requestSubscription($this->account(Platform::Twitch), $this->subscription());
    }

    private function account(Platform $platform): PlatformAccount
    {
        return new PlatformAccount(
            self::ACCOUNT_ID,
            self::STREAMER_ID,
            $platform,
            'external-id',
            'registration-id',
            null,
            null,
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
        );
    }

    private function subscription(): WebhookSubscription
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
            0,
            '0123456789abcdef0123456789abcdef',
            new DateTimeImmutable('2026-09-05 00:02:00+00:00'),
            null,
        );
    }
}
