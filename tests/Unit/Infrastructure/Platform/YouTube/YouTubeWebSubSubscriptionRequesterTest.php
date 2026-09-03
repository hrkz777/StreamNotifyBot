<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRequestFailed;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Infrastructure\Platform\YouTube\YouTubeWebSubSubscriptionRequester;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeWebSubSubscriptionRequesterTest extends TestCase
{
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000211';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000301';
    private const CHANNEL_ID = 'UC1234567890123456789012';
    private const SECRET = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function itRequestsAnAuthenticatedSubscriptionWithoutPuttingTheSecretInTheUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://pubsubhubbub.appspot.com/', $url);
            self::assertStringNotContainsString(self::SECRET, $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(10.0, $options['timeout']);
            self::assertSame(
                ['Content-Type: application/x-www-form-urlencoded'],
                $options['normalized_headers']['content-type'] ?? null,
            );
            self::assertIsString($options['body']);
            self::assertSame([
                'hub.callback' => 'https://notify.example/base/webhooks/youtube/'.self::SUBSCRIPTION_ID,
                'hub.mode' => 'subscribe',
                'hub.topic' => 'https://www.youtube.com/feeds/videos.xml?channel_id='.self::CHANNEL_ID,
                'hub.secret' => self::SECRET,
            ], self::formBody($options['body']));

            return new MockResponse('', ['http_code' => 202]);
        });

        $requester = $this->requester($client);
        $requester->requestSubscription($this->account(), $this->subscription());

        self::assertSame(Platform::YouTube, $requester->platform());
    }

    #[Test]
    public function itClassifiesServerErrorsAsRetryableWithoutExposingTheResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            'sensitive remote response',
            ['http_code' => 503],
        ));

        try {
            $this->requester($client)->requestSubscription($this->account(), $this->subscription());
            self::fail('例外が送出されること。');
        } catch (WebhookSubscriptionRequestFailed $exception) {
            self::assertTrue($exception->retryable);
            self::assertSame('http_5xx', $exception->errorCode);
            self::assertStringNotContainsString('sensitive remote response', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    #[Test]
    public function itClassifiesClientErrorsAsPermanent(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 400]));

        try {
            $this->requester($client)->requestSubscription($this->account(), $this->subscription());
            self::fail('例外が送出されること。');
        } catch (WebhookSubscriptionRequestFailed $exception) {
            self::assertFalse($exception->retryable);
            self::assertSame('http_4xx', $exception->errorCode);
        }
    }

    #[Test]
    public function itRejectsInvalidConfigurationBeforeSendingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });

        $this->expectException(WebhookSubscriptionRequestFailed::class);

        $this->requester($client, 'http://notify.example', 'short')->requestSubscription(
            $this->account(),
            $this->subscription(),
        );
    }

    private function requester(
        MockHttpClient $client,
        string $defaultUri = 'https://notify.example/base/',
        string $secret = self::SECRET,
    ): YouTubeWebSubSubscriptionRequester {
        return new YouTubeWebSubSubscriptionRequester($client, $defaultUri, $secret);
    }

    private function account(): PlatformAccount
    {
        return new PlatformAccount(
            self::ACCOUNT_ID,
            '01990d4a-0000-7000-8000-000000000201',
            Platform::YouTube,
            self::CHANNEL_ID,
            '@test',
            '@test',
            'Test Channel',
            'https://www.youtube.com/channel/'.self::CHANNEL_ID,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-03 00:00:00+00:00'),
        );
    }

    private function subscription(): WebhookSubscription
    {
        return new WebhookSubscription(
            self::SUBSCRIPTION_ID,
            self::ACCOUNT_ID,
            'channel.feed',
            null,
            WebhookSubscriptionStatus::Pending,
            null,
            new DateTimeImmutable('2026-09-03 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-03 00:00:00+00:00'),
            0,
            '00112233445566778899aabbccddeeff',
            new DateTimeImmutable('2026-09-03 00:02:00+00:00'),
            null,
        );
    }

    /** @return array<string, string> */
    private static function formBody(string $body): array
    {
        $parameters = [];
        foreach (explode('&', $body) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parameters[urldecode($name)] = urldecode($value);
        }

        return $parameters;
    }
}
