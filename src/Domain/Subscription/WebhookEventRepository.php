<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

interface WebhookEventRepository
{
    /** Returns false when the same subscription has already received this payload. */
    public function record(WebhookEvent $event): bool;

    /** @return list<WebhookEventLease> */
    public function claimPending(int $limit, string $leaseToken, int $leaseSeconds): array;

    public function markProcessed(WebhookEventLease $lease): bool;

    public function releaseClaim(WebhookEventLease $lease): bool;
}
