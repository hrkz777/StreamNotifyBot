<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Domain\Catalog\Platform;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YouTubeVideoDetailsFetcher implements YouTubeVideoDetailsProvider
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

    public function __construct(private HttpClientInterface $httpClient, private PlatformApiCredentialConfigurationLoader $credentialLoader)
    {
    }

    /**
     * @param list<mixed> $videoIds
     * @return list<YouTubeVideoDetails>
     */
    public function fetch(array $videoIds): array
    {
        $uniqueIds = [];
        foreach ($videoIds as $videoId) {
            if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) !== 1) {
                throw new InvalidArgumentException('YouTube動画IDは1件以上50件以下の11文字識別子で指定してください。');
            }
            $uniqueIds[$videoId] = true;
        }
        $ids = array_keys($uniqueIds);
        if ($ids === [] || count($ids) > 50) {
            throw new InvalidArgumentException('YouTube動画IDは1件以上50件以下の11文字識別子で指定してください。');
        }
        $credentials = $this->credentialLoader->load(Platform::YouTube);
        $apiKey = $credentials?->value('api_key');
        if (!is_string($apiKey) || preg_match('/^[\x21-\x7E]{1,255}$/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('YouTube API設定が不正です。');
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => ['Accept' => 'application/json', 'X-Goog-Api-Key' => $apiKey],
                'query' => ['part' => 'snippet,liveStreamingDetails', 'id' => implode(',', $ids), 'maxResults' => count($ids)],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new YouTubeVideoDetailsUnavailable('YouTube動画詳細を取得できませんでした。');
            }
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new YouTubeVideoDetailsUnavailable('YouTube動画詳細を取得できませんでした。');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('YouTube動画詳細の応答形式が不正です。');
        }
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            throw new InvalidArgumentException('YouTube動画詳細の応答形式が不正です。');
        }

        $details = [];
        foreach ($decoded['items'] as $item) {
            $snippet = is_array($item) ? $item['snippet'] ?? null : null;
            if (!is_array($item) || !is_string($item['id'] ?? null) || !in_array($item['id'], $ids, true) || !is_array($snippet)) {
                throw new InvalidArgumentException('YouTube動画詳細の応答形式が不正です。');
            }
            /** @var array<string, mixed> $snippet */
            $details[] = $this->detail($item['id'], $snippet, $item['liveStreamingDetails'] ?? null);
        }

        return $details;
    }

    /** @param array<string, mixed> $snippet */
    private function detail(string $videoId, array $snippet, mixed $liveStreamingDetails): YouTubeVideoDetails
    {
        $channelId = $snippet['channelId'] ?? null;
        $title = $snippet['title'] ?? null;
        $publishedAt = $this->dateTime($snippet['publishedAt'] ?? null);
        $liveBroadcastContent = $snippet['liveBroadcastContent'] ?? null;
        if (!is_string($channelId) || preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channelId) !== 1 || !is_string($title) || $title === '' || !is_string($liveBroadcastContent) || !in_array($liveBroadcastContent, ['none', 'upcoming', 'live'], true)) {
            throw new InvalidArgumentException('YouTube動画詳細の応答形式が不正です。');
        }
        if ($liveStreamingDetails !== null && !is_array($liveStreamingDetails)) {
            throw new InvalidArgumentException('YouTube動画詳細の応答形式が不正です。');
        }
        $liveDetails = is_array($liveStreamingDetails) ? $liveStreamingDetails : [];

        return new YouTubeVideoDetails($videoId, $channelId, $title, $publishedAt, $this->optionalDateTime($liveDetails['scheduledStartTime'] ?? null), $this->optionalDateTime($liveDetails['actualStartTime'] ?? null), $this->optionalDateTime($liveDetails['actualEndTime'] ?? null), $this->thumbnailUrl($snippet['thumbnails'] ?? null), $liveBroadcastContent);
    }

    private function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->dateTime($value);
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('YouTube動画詳細の日時形式が不正です。');
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException('YouTube動画詳細の日時形式が不正です。');
        }
    }

    private function thumbnailUrl(mixed $thumbnails): ?string
    {
        if (!is_array($thumbnails)) {
            return null;
        }
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            $thumbnail = $thumbnails[$size] ?? null;
            if (!is_array($thumbnail)) {
                continue;
            }
            $url = $thumbnail['url'] ?? null;
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return null;
    }
}
