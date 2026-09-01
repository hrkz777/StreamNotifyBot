<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\Twitch;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Infrastructure\Platform\Twitch\TwitchAccessTokenProvider;
use App\Infrastructure\Platform\Twitch\TwitchPlatformAccountResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitchPlatformAccountResolverTest extends TestCase
{
    #[Test]
    public function itResolvesALoginWithoutPuttingCredentialsInTheUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringNotContainsString('test-client-id', $url);
            self::assertStringNotContainsString('test-access-token', $url);
            self::assertSame(['login' => 'twitchdev'], $this->query($url));
            self::assertSame(0, $options['max_redirects']);
            $headers = $options['normalized_headers'] ?? null;
            self::assertIsArray($headers);
            $authorizationHeaders = $headers['authorization'] ?? null;
            self::assertIsArray($authorizationHeaders);
            $clientIdHeaders = $headers['client-id'] ?? null;
            self::assertIsArray($clientIdHeaders);
            self::assertSame('Authorization: Bearer test-access-token', $authorizationHeaders[0] ?? null);
            self::assertSame('Client-Id: test-client-id', $clientIdHeaders[0] ?? null);

            return new MockResponse(json_encode([
                'data' => [[
                    'id' => '141981764',
                    'login' => 'twitchdev',
                    'display_name' => 'TwitchDev',
                    'profile_image_url' => 'https://example.com/profile.png',
                    'offline_image_url' => 'https://example.com/offline.png',
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        $resolved = $this->resolver($client)->resolve('TwitchDev');

        self::assertSame(Platform::Twitch, $this->resolver($client)->platform());
        self::assertSame('141981764', $resolved->externalId);
        self::assertSame('twitchdev', $resolved->displayId);
        self::assertSame('TwitchDev', $resolved->name);
        self::assertSame('https://www.twitch.tv/twitchdev', $resolved->profileUrl);
        self::assertSame('https://example.com/profile.png', $resolved->iconUrl);
        self::assertSame('https://example.com/offline.png', $resolved->offlineImageUrl);
        self::assertNull($resolved->apiDataExpiresAt);
    }

    #[Test]
    public function itResolvesAChannelUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame(['login' => 'channel_name'], $this->query($url));

            return new MockResponse('{"data":[{"id":"1","login":"channel_name","display_name":"Channel_Name","profile_image_url":"","offline_image_url":""}]}');
        });

        $resolved = $this->resolver($client)->resolve('https://www.twitch.tv/Channel_Name');

        self::assertNull($resolved->iconUrl);
        self::assertNull($resolved->offlineImageUrl);
    }

    #[Test]
    public function itRetriesOnceWithANewTokenAfterUnauthorized(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"message":"Unauthorized"}', ['http_code' => 401]),
            new MockResponse('{"data":[{"id":"1","login":"channel","display_name":"Channel"}]}'),
        ]);
        $tokenProvider = $this->createMock(TwitchAccessTokenProvider::class);
        $tokenProvider
            ->expects($this->exactly(2))
            ->method('accessToken')
            ->willReturnOnConsecutiveCalls('expired-token', 'fresh-token');
        $tokenProvider
            ->expects($this->once())
            ->method('invalidate')
            ->with('expired-token');

        $resolved = (new TwitchPlatformAccountResolver(
            $client,
            $tokenProvider,
            'test-client-id',
        ))->resolve('channel');

        self::assertSame('1', $resolved->externalId);
        self::assertSame(2, $client->getRequestsCount());
    }

    #[Test]
    public function itReportsWhenTheUserDoesNotExist(): void
    {
        $client = new MockHttpClient(new MockResponse('{"data":[]}'));

        $this->expectException(PlatformAccountNotFound::class);

        $this->resolver($client)->resolve('missing');
    }

    #[Test]
    public function itRejectsUnsupportedUrlsBeforeObtainingAToken(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });
        $tokenProvider = $this->createMock(TwitchAccessTokenProvider::class);
        $tokenProvider->expects($this->never())->method('accessToken');

        $this->expectException(InvalidArgumentException::class);

        (new TwitchPlatformAccountResolver(
            $client,
            $tokenProvider,
            'test-client-id',
        ))->resolve('https://example.com/channel');
    }

    #[Test]
    public function itHidesRemoteErrorDetails(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"message":"test-access-token"}',
            ['http_code' => 500],
        ));

        try {
            $this->resolver($client)->resolve('channel');
            self::fail('例外が送出されること。');
        } catch (PlatformAccountResolutionFailed $exception) {
            self::assertStringNotContainsString('test-access-token', $exception->getMessage());
        }
    }

    private function resolver(MockHttpClient $client): TwitchPlatformAccountResolver
    {
        $tokenProvider = $this->createStub(TwitchAccessTokenProvider::class);
        $tokenProvider->method('accessToken')->willReturn('test-access-token');

        return new TwitchPlatformAccountResolver($client, $tokenProvider, 'test-client-id');
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);

        /** @var array<string, string> $parameters */
        return $parameters;
    }
}
