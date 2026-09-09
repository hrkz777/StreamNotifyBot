<?php

declare(strict_types=1);

namespace App\Application\Subscription;

final readonly class WebhookEventProcessingResult
{
    public function __construct(
        public int $claimedCount,
        public int $processedCount,
        public int $discardedCount,
        public int $releasedCount,
        public int $staleResultCount,
    ) {
    }
}
