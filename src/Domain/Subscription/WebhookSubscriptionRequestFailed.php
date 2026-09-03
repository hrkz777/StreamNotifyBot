<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Catalog\Platform;
use RuntimeException;

final class WebhookSubscriptionRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly Platform $platform,
        public readonly string $errorCode,
        public readonly bool $retryable,
    ) {
        parent::__construct(sprintf('%sのWebhook購読要求に失敗しました。', $platform->displayId()));
    }
}
