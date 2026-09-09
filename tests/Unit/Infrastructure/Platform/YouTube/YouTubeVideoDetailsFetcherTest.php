<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsFetcher;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetails;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeVideoDetailsFetcherTest extends TestCase
{
    #[Test]
    public function itFetchesUpToFiftyVideoDetailsWithoutPuttingTheApiKeyInTheUrl(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringNotContainsString('test-api-key', $url);
            self::assertSame('snippet,liveStreamingDetails', $this->query($url)['part'] ?? null);
            self::assertSame('abcdefghijk,lmnopqrstuv', $this->query($url)['id'] ?? null);
            $normalizedHeaders = $options['normalized_headers'] ?? null;
            self::assertIsArray($normalizedHeaders);
            $apiKeyHeaders = $normalizedHeaders['x-goog-api-key'] ?? null;
            self::assertIsArray($apiKeyHeaders);
            self::assertSame('X-Goog-Api-Key: test-api-key', $apiKeyHeaders[0] ?? null);

            return new MockResponse(json_encode(['items' => [[
                'id' => 'abcdefghijk',
                'snippet' => [
                    'channelId' => 'UCabcdefghijklmnopqrstuv',
                    'title' => '配信タイトル',
                    'publishedAt' => '2026-09-09T00:00:00Z',
                    'liveBroadcastContent' => 'live',
                    'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/example.jpg']],
                ],
                'liveStreamingDetails' => ['scheduledStartTime' => '2026-09-09T01:00:00Z'],
            ]]], JSON_THROW_ON_ERROR));
        });

        $details = (new YouTubeVideoDetailsFetcher($client, 'test-api-key'))->fetch(['abcdefghijk', 'lmnopqrstuv']);

        self::assertCount(1, $details);
        $detail = $details[0];
        self::assertInstanceOf(YouTubeVideoDetails::class, $detail);
        self::assertSame('abcdefghijk', $detail->videoId);
        self::assertSame('UCabcdefghijklmnopqrstuv', $detail->channelId);
        self::assertSame('配信タイトル', $detail->title);
        self::assertSame('live', $detail->liveBroadcastContent);
        self::assertSame('https://i.ytimg.com/example.jpg', $detail->thumbnailUrl);
        self::assertEquals(new \DateTimeImmutable('2026-09-09T01:00:00+00:00'), $detail->scheduledStartAt);
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
