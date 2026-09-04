<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum WebhookSubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
    case Expired = 'expired';
}
