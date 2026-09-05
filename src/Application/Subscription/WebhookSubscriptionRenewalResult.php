<?php

declare(strict_types=1);

namespace App\Application\Subscription;

final readonly class WebhookSubscriptionRenewalResult
{
    public function __construct(
        public int $claimedCount,
        public int $acceptedCount,
        public int $retryScheduledCount,
        public int $permanentlyFailedCount,
        public int $releasedCount,
        public int $staleResultCount,
    ) {
    }
}
