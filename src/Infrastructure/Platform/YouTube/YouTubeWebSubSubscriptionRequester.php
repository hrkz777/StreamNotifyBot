<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Security\SecretDecryptionFailed;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookCallbackUrlRepository;
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
        private PlatformApiCredentialConfigurationLoader $credentialLoader,
        private WebhookCallbackUrlRepository $callbackUrlRepository,
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
        try {
            $credentials = $this->credentialLoader->load(Platform::YouTube);
        } catch (InvalidArgumentException|SecretDecryptionFailed) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }
        $secret = $credentials?->value('websub_secret');
        if (!is_string($secret)) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }
        $callbackUrl = $this->callbackUrlRepository->find();
        if ($callbackUrl === null) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }

        try {
            $this->assertRequest($account, $subscription, $secret);

            try {
                $response = $this->httpClient->request('POST', self::HUB_URL, [
                    'body' => [
                        'hub.callback' => sprintf(
                            '%s/webhooks/youtube/%s',
                            rtrim($callbackUrl->value, '/'),
                            $subscription->id,
                        ),
                        'hub.mode' => 'subscribe',
                        'hub.topic' => sprintf(
                            'https://www.youtube.com/feeds/videos.xml?channel_id=%s',
                            $account->externalId,
                        ),
                        'hub.secret' => $secret,
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
        } finally {
            sodium_memzero($secret);
        }
    }

    private function assertRequest(PlatformAccount $account, WebhookSubscription $subscription, string $secret): void
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

        if (preg_match('/^[\x21-\x7E]{32,199}$/D', $secret) !== 1) {
            throw new WebhookSubscriptionRequestFailed(Platform::YouTube, 'invalid_configuration', false);
        }
    }
}
