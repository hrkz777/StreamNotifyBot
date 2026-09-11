<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YouTubeChannelFeedFetcher implements YouTubeChannelFeedProvider
{
    private const ENDPOINT = 'https://www.youtube.com/feeds/videos.xml';

    public function __construct(private HttpClientInterface $httpClient, private YouTubeAtomFeedParser $parser)
    {
    }

    /** @return list<YouTubeAtomFeedEntry> */
    public function fetch(string $channelId): array
    {
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channelId) !== 1) {
            throw new InvalidArgumentException('YouTubeチャンネルIDの形式が不正です。');
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => ['Accept' => 'application/atom+xml'],
                'query' => ['channel_id' => $channelId],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new YouTubeVideoDetailsUnavailable('YouTubeチャンネルフィードを取得できませんでした。');
            }
            $entries = $this->parser->parse($response->getContent(false));
        } catch (TransportExceptionInterface) {
            throw new YouTubeVideoDetailsUnavailable('YouTubeチャンネルフィードを取得できませんでした。');
        }

        foreach ($entries as $entry) {
            if ($entry->channelId !== $channelId) {
                throw new InvalidArgumentException('YouTubeチャンネルフィードのチャンネルIDが一致しません。');
            }
        }

        return $entries;
    }
}
