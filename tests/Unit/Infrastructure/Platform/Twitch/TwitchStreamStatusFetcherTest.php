<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\Twitch;

use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Domain\Catalog\Platform;
use App\Infrastructure\Platform\Twitch\TwitchAccessTokenProvider;
use App\Infrastructure\Platform\Twitch\TwitchStreamStatusFetcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitchStreamStatusFetcherTest extends TestCase
{
    #[Test]
    public function itRetriesOnceAfterUnauthorizedAndReturnsOfflineAccounts(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"message":"Unauthorized"}', ['http_code' => 401]),
            new MockResponse('{"data":[{"id":"stream-1","user_id":"100","title":"配信中","started_at":"2026-09-09T00:00:00Z","thumbnail_url":"https://example.com/{width}x{height}.jpg"}]}'),
        ]);
        $tokens = $this->createMock(TwitchAccessTokenProvider::class);
        $tokens->expects(self::exactly(2))->method('accessToken')->willReturnOnConsecutiveCalls('expired', 'fresh');
        $tokens->expects(self::once())->method('invalidate')->with('expired');

        $credentials = $this->createStub(PlatformApiCredentialConfigurationLoader::class);
        $credentials->method('load')->willReturn(PlatformApiCredentialConfiguration::twitch('test-client-id', 'test-client-secret'));
        $statuses = (new TwitchStreamStatusFetcher($client, $tokens, $credentials))->fetch(['100', '200']);

        self::assertCount(2, $statuses);
        self::assertTrue($statuses[0]->isLive);
        self::assertSame('stream-1', $statuses[0]->streamId);
        self::assertSame('https://example.com/1280x720.jpg', $statuses[0]->thumbnailUrl);
        self::assertFalse($statuses[1]->isLive);
        self::assertNull($statuses[1]->streamId);
    }
}
