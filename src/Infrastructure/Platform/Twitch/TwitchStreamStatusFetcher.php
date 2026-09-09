<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwitchStreamStatusFetcher implements TwitchStreamStatusProvider
{
    private const ENDPOINT = 'https://api.twitch.tv/helix/streams';

    public function __construct(private HttpClientInterface $httpClient, private TwitchAccessTokenProvider $accessTokenProvider, private string $clientId)
    {
    }

    /**
     * @param list<mixed> $userIds
     * @return list<TwitchStreamStatus>
     */
    public function fetch(array $userIds): array
    {
        $idSet = [];
        foreach ($userIds as $userId) {
            if (!is_string($userId) || preg_match('/^[\x21-\x7E]{1,255}$/D', $userId) !== 1) {
                throw new \InvalidArgumentException('TwitchユーザーIDは1件以上100件以下のASCII文字列で指定してください。');
            }
            $idSet[$userId] = true;
        }
        $ids = array_keys($idSet);
        if ($ids === [] || count($ids) > 100 || preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientId) !== 1) {
            throw new \InvalidArgumentException('Twitchストリーム取得設定が不正です。');
        }
        $token = $this->accessTokenProvider->accessToken();
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Client-Id' => $this->clientId], 'query' => ['user_id' => $ids], 'max_redirects' => 0, 'timeout' => 10.0]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Twitchストリーム状態を取得できませんでした。');
            }
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new \RuntimeException('Twitchストリーム状態を取得できませんでした。');
        }
        try { /** @var mixed $decoded */ $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('Twitchストリーム状態の応答形式が不正です。');
        }
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new \InvalidArgumentException('Twitchストリーム状態の応答形式が不正です。');
        }
        $live = [];
        foreach ($decoded['data'] as $stream) {
            if (!is_array($stream) || !is_string($stream['user_id'] ?? null) || !isset($idSet[$stream['user_id']]) || !is_string($stream['id'] ?? null) || !is_string($stream['title'] ?? null) || !is_string($stream['started_at'] ?? null)) {
                throw new \InvalidArgumentException('Twitchストリーム状態の応答形式が不正です。');
            }
            try {
                $startedAt = new DateTimeImmutable($stream['started_at'], new DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \InvalidArgumentException('Twitchストリーム状態の日時形式が不正です。');
            }
            $thumbnail = is_string($stream['thumbnail_url'] ?? null) ? str_replace(['{width}', '{height}'], ['1280', '720'], $stream['thumbnail_url']) : null;
            $live[$stream['user_id']] = new TwitchStreamStatus($stream['user_id'], true, $stream['id'], $stream['title'], $startedAt, $thumbnail);
        }
        return array_map(static fn (string $id): TwitchStreamStatus => $live[$id] ?? new TwitchStreamStatus($id, false, null, null, null, null), $ids);
    }
}
