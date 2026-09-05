<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum JobType: string
{
    case WebhookEvent = 'webhook_event';
    case StreamPolling = 'stream_polling';
    case SubscriptionRenewal = 'subscription_renewal';
    case Notification = 'notification';
    case Cleanup = 'cleanup';
}
