<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\TwitCasting;

use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Infrastructure\Platform\TwitCasting\TwitCastingLiveStatusFetcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwitCastingLiveStatusFetcherTest extends TestCase
{
    #[Test]
    public function itFetchesAndNormalizesALiveMovie(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://apiv2.twitcasting.tv/users/user-123/current_live', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(10.0, $options['timeout']);

            return new MockResponse('{"movie":{"id":"1234567890","title":"ライブ配信","created":1788912000}}');
        });

        $status = (new TwitCastingLiveStatusFetcher($client, $this->credentials()))->fetch('user-123');

        self::assertTrue($status->isLive);
        self::assertSame('1234567890', $status->movieId);
        self::assertSame('ライブ配信', $status->title);
        self::assertSame('2026-09-09T00:00:00+00:00', $status->startedAt?->format(DATE_ATOM));
    }

    #[Test]
    public function itReturnsAnOfflineStatusWhenThereIsNoCurrentMovie(): void
    {
        $client = new MockHttpClient(new MockResponse('{"movie":null}'));

        $status = (new TwitCastingLiveStatusFetcher($client, $this->credentials()))->fetch('user-123');

        self::assertFalse($status->isLive);
        self::assertNull($status->movieId);
        self::assertNull($status->title);
        self::assertNull($status->startedAt);
    }

    #[Test]
    public function itRejectsMalformedMovieResponses(): void
    {
        $client = new MockHttpClient(new MockResponse('{"movie":{"id":123}}'));

        $this->expectException(InvalidArgumentException::class);
        (new TwitCastingLiveStatusFetcher($client, $this->credentials()))->fetch('user-123');
    }

    private function credentials(): PlatformApiCredentialConfigurationLoader
    {
        $loader = $this->createStub(PlatformApiCredentialConfigurationLoader::class);
        $loader->method('load')->willReturn(PlatformApiCredentialConfiguration::twitCasting('test-client', 'test-secret'));

        return $loader;
    }
}
