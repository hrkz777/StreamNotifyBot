<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\Twitch;

use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\System\Clock;
use App\Infrastructure\Platform\Twitch\TwitchClientCredentialsTokenProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitchClientCredentialsTokenProviderTest extends TestCase
{
    #[Test]
    public function itObtainsAndReusesAnAppAccessTokenWithoutPuttingSecretsInTheUrl(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertSame('POST', $method);
            self::assertSame('https://id.twitch.tv/oauth2/token', $url);
            self::assertStringNotContainsString('test-client-id', $url);
            self::assertStringNotContainsString('test-client-secret', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertIsString($options['body']);
            parse_str($options['body'], $body);
            self::assertSame([
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'grant_type' => 'client_credentials',
            ], $body);

            return new MockResponse(json_encode([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ], JSON_THROW_ON_ERROR));
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnOnConsecutiveCalls(
            new DateTimeImmutable('2026-09-02 00:00:00+00:00'),
            new DateTimeImmutable('2026-09-02 00:30:00+00:00'),
        );
        $provider = new TwitchClientCredentialsTokenProvider(
            $client,
            $clock,
            'test-client-id',
            'test-client-secret',
        );

        self::assertSame('test-access-token', $provider->accessToken());
        self::assertSame('test-access-token', $provider->accessToken());
        self::assertSame(1, $requestCount);
    }

    #[Test]
    public function itObtainsANewTokenAfterInvalidation(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"access_token":"first-token","expires_in":3600,"token_type":"bearer"}'),
            new MockResponse('{"access_token":"second-token","expires_in":3600,"token_type":"bearer"}'),
        ]);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02 00:00:00+00:00'));
        $provider = new TwitchClientCredentialsTokenProvider($client, $clock, 'client', 'secret');

        $firstToken = $provider->accessToken();
        $provider->invalidate($firstToken);

        self::assertSame('second-token', $provider->accessToken());
        self::assertSame(2, $client->getRequestsCount());
    }

    #[Test]
    public function itRejectsMissingCredentialsBeforeSendingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02 00:00:00+00:00'));

        $this->expectException(PlatformAccountResolutionFailed::class);

        (new TwitchClientCredentialsTokenProvider($client, $clock, '', ''))->accessToken();
    }

    #[Test]
    public function itHidesAuthenticationErrorDetails(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"message":"test-client-secret"}',
            ['http_code' => 401],
        ));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02 00:00:00+00:00'));

        try {
            (new TwitchClientCredentialsTokenProvider(
                $client,
                $clock,
                'test-client-id',
                'test-client-secret',
            ))->accessToken();
            self::fail('例外が送出されること。');
        } catch (PlatformAccountResolutionFailed $exception) {
            self::assertStringNotContainsString('test-client-secret', $exception->getMessage());
        }
    }
}
