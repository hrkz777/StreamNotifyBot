<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\System\Clock;
use DateInterval;

final readonly class ConfirmYouTubeWebhookSubscription
{
    private const SUBSCRIPTION_TYPE = 'channel.feed';
    private const MAX_LEASE_SECONDS = 31536000;

    public function __construct(
        private WebhookSubscriptionRepository $subscriptionRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private Clock $clock,
    ) {
    }

    public function confirm(string $subscriptionId, string $topic, int $leaseSeconds): bool
    {
        if ($leaseSeconds < 1 || $leaseSeconds > self::MAX_LEASE_SECONDS) {
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($subscriptionId);
        if ($subscription === null || $subscription->subscriptionType !== self::SUBSCRIPTION_TYPE) {
            return false;
        }

        $account = $this->streamerCatalogRepository->findPlatformAccountById($subscription->platformAccountId);
        if ($account === null || $account->platform !== Platform::YouTube) {
            return false;
        }

        $expectedTopic = sprintf(
            'https://www.youtube.com/feeds/videos.xml?channel_id=%s',
            $account->externalId,
        );
        if (!hash_equals($expectedTopic, $topic)) {
            return false;
        }

        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds)));
        $renewAfter = $now->add(new DateInterval(sprintf('PT%dS', max(1, intdiv($leaseSeconds * 4, 5)))));

        return $this->subscriptionRepository->confirmVerification(
            $subscription->id,
            $expiresAt,
            $renewAfter,
        );
    }
}
