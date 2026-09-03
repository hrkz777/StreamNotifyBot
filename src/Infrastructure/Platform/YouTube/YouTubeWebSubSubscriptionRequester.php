<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRequester;
use App\Domain\Subscription\WebhookSubscriptionRequestFailed;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YouTubeWebSubSubscriptionRequester implements WebhookSubscriptionRequester
{
    private const HUB_URL = 'https://pubsubhubbub.appspot.com/';
    private const SUBSCRIPTION_TYPE = 'channel.feed';
    private const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/D';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $defaultUri,
        private string $secret,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function requestSubscription(
        PlatformAccount $account,
        WebhookSubscription $subscription,
    ): void {
        $this->assertRequest($account, $subscription);

        try {
            $response = $this->httpClient->request('POST', self::HUB_URL, [
                'body' => [
                    'hub.callback' => sprintf(
                        '%s/webhooks/youtube/%s',
                        rtrim($this->defaultUri, '/'),
                        $subscription->id,
                    ),
                    'hub.mode' => 'subscribe',
                    'hub.topic' => sprintf(
                        'https://www.youtube.com/feeds/videos.xml?channel_id=%s',
                        $account->externalId,
                    ),
                    'hub.secret' => $this->secret,
                ],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'transport_error', true);
        }

        if ($statusCode === 202) {
            return;
        }

        if ($statusCode === 429) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'http_429', true);
        }

        if ($statusCode >= 500) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'http_5xx', true);
        }

        if ($statusCode >= 400) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'http_4xx', false);
        }

        throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'unexpected_status', false);
    }

    private function assertRequest(PlatformAccount $account, WebhookSubscription $subscription): void
    {
        if (
            $account->platform !== Platform::YouTube
            || $subscription->platformAccountId !== $account->id
            || $subscription->subscriptionType !== self::SUBSCRIPTION_TYPE
            || $subscription->processingLeaseToken === null
            || preg_match(self::CHANNEL_ID_PATTERN, $account->externalId) !== 1
        ) {
            throw new InvalidArgumentException('YouTube WebSub購読要求の対象が不正です。');
        }

        $uriParts = parse_url($this->defaultUri);
        if (
            !is_array($uriParts)
            || filter_var($this->defaultUri, FILTER_VALIDATE_URL) === false
            || ($uriParts['scheme'] ?? null) !== 'https'
            || !is_string($uriParts['host'] ?? null)
            || isset($uriParts['user'])
            || isset($uriParts['pass'])
            || isset($uriParts['query'])
            || isset($uriParts['fragment'])
        ) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }

        if (preg_match('/^[\x21-\x7E]{32,199}$/D', $this->secret) !== 1) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }
    }
}
