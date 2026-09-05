<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

interface WebhookSubscriptionRepository
{
    public function add(WebhookSubscription $subscription): void;

    public function findById(string $id): ?WebhookSubscription;

    public function findByAccountAndType(string $platformAccountId, string $subscriptionType): ?WebhookSubscription;

    /** @return list<WebhookSubscription> */
    public function claimDue(int $limit, string $leaseToken, int $leaseSeconds): array;

    public function saveClaimResult(WebhookSubscription $subscription): bool;

    public function releaseClaim(WebhookSubscription $subscription): bool;
}
