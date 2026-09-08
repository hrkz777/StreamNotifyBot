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

    public function __construct(
        private WebhookSubscriptionRepository $subscriptionRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private Clock $clock,
    ) {
    }

    public function confirm(string $subscriptionId, string $topic): bool
    {
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

        return $this->subscriptionRepository->confirmVerification(
            $subscription->id,
            $this->clock->now()->add(new DateInterval('P1D')),
        );
    }
}
