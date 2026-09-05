<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Catalog\PlatformAccount;

interface WebhookSubscriptionRequestDispatcher
{
    public function requestSubscription(
        PlatformAccount $account,
        WebhookSubscription $subscription,
    ): void;
}
