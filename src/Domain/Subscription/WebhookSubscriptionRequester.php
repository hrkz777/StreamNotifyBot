<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;

interface WebhookSubscriptionRequester
{
    public function platform(): Platform;

    public function requestSubscription(
        PlatformAccount $account,
        WebhookSubscription $subscription,
    ): void;
}
