<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

interface WebhookEventRepository
{
    /** Returns false when the same subscription has already received this payload. */
    public function record(WebhookEvent $event): bool;
}
