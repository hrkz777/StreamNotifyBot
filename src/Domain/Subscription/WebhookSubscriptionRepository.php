<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

interface WebhookSubscriptionRepository
{
    public function add(WebhookSubscription $subscription): void;

    public function findById(string $id): ?WebhookSubscription;

    public function findByAccountAndType(string $platformAccountId, string $subscriptionType): ?WebhookSubscription;
}
