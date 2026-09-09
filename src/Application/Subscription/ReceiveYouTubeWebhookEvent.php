<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventRepository;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;

final readonly class ReceiveYouTubeWebhookEvent
{
    private const SUBSCRIPTION_TYPE = 'channel.feed';

    public function __construct(
        private WebhookSubscriptionRepository $subscriptionRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private WebhookEventRepository $eventRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function receive(string $subscriptionId, string $payload): bool
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);
        if (
            $subscription === null
            || $subscription->status !== WebhookSubscriptionStatus::Active
            || $subscription->subscriptionType !== self::SUBSCRIPTION_TYPE
        ) {
            return false;
        }

        $account = $this->streamerCatalogRepository->findPlatformAccountById($subscription->platformAccountId);
        if ($account === null || !$account->isEnabled || $account->platform !== Platform::YouTube) {
            return false;
        }

        $this->eventRepository->record(new WebhookEvent(
            $this->idGenerator->generate(),
            $subscription->id,
            $payload,
            $this->clock->now(),
        ));

        return true;
    }
}
