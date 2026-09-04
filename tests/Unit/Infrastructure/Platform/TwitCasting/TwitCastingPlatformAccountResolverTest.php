<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\TwitCasting;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Infrastructure\Platform\TwitCasting\TwitCastingPlatformAccountResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitCastingPlatformAccountResolverTest extends TestCase
{
    #[Test]
    public function itResolvesAScreenIdWithoutPuttingCredentialsInTheUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://apiv2.twitcasting.tv/users/twitcasting_jp', $url);
            self::assertStringNotContainsString('test-client-id', $url);
            self::assertStringNotContainsString('test-client-secret', $url);
            self::assertSame(0, $options['max_redirects']);
            $headers = $options['normalized_headers'] ?? null;
            self::assertIsArray($headers);
            $authorizationHeaders = $headers['authorization'] ?? null;
            self::assertIsArray($authorizationHeaders);
            $versionHeaders = $headers['x-api-version'] ?? null;
            self::assertIsArray($versionHeaders);
            self::assertSame(
                'Authorization: Basic '.base64_encode('test-client-id:test-client-secret'),
                $authorizationHeaders[0] ?? null,
            );
            self::assertSame('X-Api-Version: 2.0', $versionHeaders[0] ?? null);

            return new MockResponse(json_encode([
                'user' => [
                    'id' => '182224938',
                    'screen_id' => 'twitcasting_jp',
                    'name' => 'ツイキャス公式',
                    'image' => 'http://example.com/profile.png',
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $resolved = $this->resolver($client)->resolve('twitcasting_jp');

        self::assertSame(Platform::TwitCasting, $this->resolver($client)->platform());
        self::assertSame('182224938', $resolved->externalId);
        self::assertSame('twitcasting_jp', $resolved->displayId);
        self::assertSame('ツイキャス公式', $resolved->name);
        self::assertSame('https://twitcasting.tv/twitcasting_jp', $resolved->profileUrl);
        self::assertSame('https://example.com/profile.png', $resolved->iconUrl);
        self::assertNull($resolved->offlineImageUrl);
        self::assertNull($resolved->apiDataExpiresAt);
    }

    #[Test]
    public function itResolvesAnEncodedCasAccountUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://apiv2.twitcasting.tv/users/c%3Achannel', $url);

            return new MockResponse('{"user":{"id":"1","screen_id":"c:channel","name":"Channel","image":""}}');
        });

        $resolved = $this->resolver($client)->resolve('https://twitcasting.tv/c%3Achannel');

        self::assertSame('c:channel', $resolved->displayId);
        self::assertSame('https://twitcasting.tv/c%3Achannel', $resolved->profileUrl);
        self::assertNull($resolved->iconUrl);
    }

    #[Test]
    public function itReportsNotFoundWithoutExposingTheResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"error":{"message":"test-client-secret"}}',
            ['http_code' => 404],
        ));

        try {
            $this->resolver($client)->resolve('missing');
            self::fail('例外が送出されること。');
        } catch (PlatformAccountNotFound $exception) {
            self::assertStringNotContainsString('test-client-secret', $exception->getMessage());
        }
    }

    #[Test]
    public function itRejectsUnsupportedUrlsBeforeSendingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });

        $this->expectException(InvalidArgumentException::class);

        $this->resolver($client)->resolve('https://example.com/channel');
    }

    #[Test]
    public function itRejectsMissingCredentialsBeforeSendingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });

        $this->expectException(PlatformAccountResolutionFailed::class);

        (new TwitCastingPlatformAccountResolver($client, '', ''))->resolve('channel');
    }

    #[Test]
    public function itHidesRemoteErrorDetails(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"error":{"message":"test-client-secret"}}',
            ['http_code' => 500],
        ));

        try {
            $this->resolver($client)->resolve('channel');
            self::fail('例外が送出されること。');
        } catch (PlatformAccountResolutionFailed $exception) {
            self::assertStringNotContainsString('test-client-secret', $exception->getMessage());
        }
    }

    #[Test]
    public function itRejectsAnUnsafeImageUrlFromTheApi(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"user":{"id":"1","screen_id":"channel","name":"Channel","image":"javascript:alert(1)"}}',
        ));

        $this->expectException(PlatformAccountResolutionFailed::class);

        $this->resolver($client)->resolve('channel');
    }

    private function resolver(MockHttpClient $client): TwitCastingPlatformAccountResolver
    {
        return new TwitCastingPlatformAccountResolver(
            $client,
            'test-client-id',
            'test-client-secret',
        );
    }
}
