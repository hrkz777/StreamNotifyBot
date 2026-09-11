<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\TwitCasting;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwitCastingLiveStatusFetcher implements TwitCastingLiveStatusProvider
{
    private const ENDPOINT = 'https://apiv2.twitcasting.tv/users/';

    public function __construct(private HttpClientInterface $httpClient, private string $clientId, private string $clientSecret)
    {
    }

    public function fetch(string $userId): TwitCastingLiveStatus
    {
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $userId) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $this->clientId) !== 1 || preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientSecret) !== 1) {
            throw new \InvalidArgumentException('TwitCasting配信状態取得設定が不正です。');
        }
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT.rawurlencode($userId).'/current_live', ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret), 'X-Api-Version' => '2.0'], 'max_redirects' => 0, 'timeout' => 10.0]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('TwitCasting配信状態を取得できませんでした。');
            }
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new \RuntimeException('TwitCasting配信状態を取得できませんでした。');
        }
        try { /** @var mixed $decoded */ $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('TwitCasting配信状態の応答形式が不正です。');
        }
        $movie = is_array($decoded) ? ($decoded['movie'] ?? null) : null;
        if ($movie === null) {
            return new TwitCastingLiveStatus($userId, false, null, null, null);
        }
        if (!is_array($movie) || !is_string($movie['id'] ?? null) || !is_string($movie['title'] ?? null) || !is_numeric($movie['created'] ?? null)) {
            throw new \InvalidArgumentException('TwitCasting配信状態の応答形式が不正です。');
        }
        return new TwitCastingLiveStatus($userId, true, $movie['id'], $movie['title'], (new DateTimeImmutable('@'.(string) $movie['created']))->setTimezone(new DateTimeZone('UTC')));
    }
}
