<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\System\Clock;
use App\Infrastructure\Platform\YouTube\YouTubePlatformAccountResolver;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubePlatformAccountResolverTest extends TestCase
{
    private const CHANNEL_ID = 'UC1234567890123456789012';

    #[Test]
    public function itResolvesAHandleWithoutPuttingTheApiKeyInTheUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringNotContainsString('test-api-key', $url);
            self::assertSame(0, $options['max_redirects']);
            $normalizedHeaders = $options['normalized_headers'] ?? null;
            self::assertIsArray($normalizedHeaders);
            $apiKeyHeaders = $normalizedHeaders['x-goog-api-key'] ?? null;
            self::assertIsArray($apiKeyHeaders);
            self::assertSame('X-Goog-Api-Key: test-api-key', $apiKeyHeaders[0] ?? null);
            self::assertSame([
                'part' => 'snippet',
                'maxResults' => '1',
                'forHandle' => '@resolved',
            ], $this->query($url));

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => [
                        'customUrl' => '@resolved',
                        'title' => 'Resolved Channel',
                        'thumbnails' => [
                            'default' => ['url' => 'https://example.com/default.png'],
                            'high' => ['url' => 'https://example.com/high.png'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        $resolved = $this->resolver($client)->resolve('@resolved');

        self::assertSame(Platform::YouTube, $this->resolver($client)->platform());
        self::assertSame(self::CHANNEL_ID, $resolved->externalId);
        self::assertSame('@resolved', $resolved->displayId);
        self::assertSame('Resolved Channel', $resolved->name);
        self::assertSame('https://www.youtube.com/channel/'.self::CHANNEL_ID, $resolved->profileUrl);
        self::assertSame('https://example.com/high.png', $resolved->iconUrl);
        self::assertNull($resolved->offlineImageUrl);
        self::assertEquals(new DateTimeImmutable('2026-10-02 00:00:00+00:00'), $resolved->apiDataExpiresAt);
    }

    #[Test]
    public function itResolvesAChannelUrlByItsStableId(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame([
                'part' => 'snippet',
                'maxResults' => '1',
                'id' => self::CHANNEL_ID,
            ], $this->query($url));

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => [],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        $resolved = $this->resolver($client)->resolve(
            'https://www.youtube.com/channel/'.self::CHANNEL_ID,
        );

        self::assertSame(self::CHANNEL_ID, $resolved->externalId);
        self::assertNull($resolved->displayId);
        self::assertNull($resolved->name);
        self::assertNull($resolved->iconUrl);
    }

    #[Test]
    public function itSupportsLegacyUsernameUrls(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('legacy-name', $this->query($url)['forUsername']);

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => [],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->resolver($client)->resolve('https://youtube.com/user/legacy-name');
    }

    #[Test]
    public function itAcceptsAHandleWithoutTheAtSign(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('bare-handle', $this->query($url)['forHandle']);

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => self::CHANNEL_ID,
                    'snippet' => [],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        $this->resolver($client)->resolve('bare-handle');
    }

    #[Test]
    public function itReportsWhenTheChannelDoesNotExist(): void
    {
        $client = new MockHttpClient(new MockResponse('{"items":[]}'));

        $this->expectException(PlatformAccountNotFound::class);

        $this->resolver($client)->resolve('@missing');
    }

    #[Test]
    public function itRejectsUnsupportedIdentifiersBeforeSendingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });

        $this->expectException(InvalidArgumentException::class);

        $this->resolver($client)->resolve('https://example.com/@channel');
    }

    #[Test]
    public function itDoesNotSendARequestWithoutAnApiKey(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTPリクエストは送信されないこと。');
        });

        $this->expectException(PlatformAccountResolutionFailed::class);

        $this->resolver($client, '')->resolve('@channel');
    }

    #[Test]
    public function itHidesRemoteErrorDetailsBehindTheDomainBoundary(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"error":{"message":"sensitive remote response"}}',
            ['http_code' => 403],
        ));

        try {
            $this->resolver($client)->resolve('@channel');
            self::fail('例外が送出されること。');
        } catch (PlatformAccountResolutionFailed $exception) {
            self::assertStringNotContainsString('sensitive remote response', $exception->getMessage());
            self::assertStringNotContainsString('test-api-key', $exception->getMessage());
        }
    }

    private function resolver(MockHttpClient $client, string $apiKey = 'test-api-key'): YouTubePlatformAccountResolver
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02 00:00:00+00:00'));

        return new YouTubePlatformAccountResolver($client, $clock, $apiKey);
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
