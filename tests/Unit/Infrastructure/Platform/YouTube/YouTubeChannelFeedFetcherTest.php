<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedParser;
use App\Infrastructure\Platform\YouTube\YouTubeChannelFeedFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeChannelFeedFetcherTest extends TestCase
{
    #[Test]
    public function itFetchesAndValidatesTheRequestedChannelFeed(): void
    {
        $channelId = 'UCabcdefghijklmnopqrstuv';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($channelId): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId, $url);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse(sprintf('<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015"><entry><yt:videoId>abcdefghijk</yt:videoId><yt:channelId>%s</yt:channelId></entry></feed>', $channelId));
        });

        $entries = (new YouTubeChannelFeedFetcher($client, new YouTubeAtomFeedParser()))->fetch($channelId);

        self::assertCount(1, $entries);
        self::assertSame('abcdefghijk', $entries[0]->videoId);
    }
}
