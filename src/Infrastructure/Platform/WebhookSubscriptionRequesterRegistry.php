<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\UnsupportedPlatform;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRequestDispatcher;
use App\Domain\Subscription\WebhookSubscriptionRequester;
use InvalidArgumentException;

final readonly class WebhookSubscriptionRequesterRegistry implements WebhookSubscriptionRequestDispatcher
{
    /** @var array<string, WebhookSubscriptionRequester> */
    private array $requesters;

    /** @param iterable<WebhookSubscriptionRequester> $requesters */
    public function __construct(iterable $requesters)
    {
        $indexedRequesters = [];
        foreach ($requesters as $requester) {
            $platformCode = $requester->platform()->value;
            if (isset($indexedRequesters[$platformCode])) {
                throw new InvalidArgumentException(sprintf(
                    'プラットフォーム「%s」のWebhook購読Requesterが重複しています。',
                    $requester->platform()->displayId(),
                ));
            }

            $indexedRequesters[$platformCode] = $requester;
        }

        $this->requesters = $indexedRequesters;
    }

    public function requestSubscription(
        PlatformAccount $account,
        WebhookSubscription $subscription,
    ): void {
        $requester = $this->requesters[$account->platform->value]
            ?? throw new UnsupportedPlatform($account->platform);

        $requester->requestSubscription($account, $subscription);
    }
}
