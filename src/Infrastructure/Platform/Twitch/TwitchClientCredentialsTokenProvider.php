<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\System\Clock;
use DateTimeImmutable;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TwitchClientCredentialsTokenProvider implements TwitchAccessTokenProvider
{
    private const ENDPOINT = 'https://id.twitch.tv/oauth2/token';

    private ?string $cachedAccessToken = null;
    private ?DateTimeImmutable $refreshAt = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Clock $clock,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function accessToken(): string
    {
        $now = $this->clock->now();
        if (
            $this->cachedAccessToken !== null
            && $this->refreshAt !== null
            && $now < $this->refreshAt
        ) {
            return $this->cachedAccessToken;
        }

        $this->assertCredentials();

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        if ($statusCode !== 200) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        if (
            !is_array($decoded)
            || !is_string($decoded['access_token'] ?? null)
            || preg_match('/^[\x21-\x7E]{1,2048}$/D', $decoded['access_token']) !== 1
            || !is_int($decoded['expires_in'] ?? null)
            || $decoded['expires_in'] <= 0
            || ($decoded['token_type'] ?? null) !== 'bearer'
        ) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        $this->cachedAccessToken = $decoded['access_token'];
        $refreshSeconds = max(0, $decoded['expires_in'] - 60);
        $this->refreshAt = $now->modify(sprintf('+%d seconds', $refreshSeconds));

        return $this->cachedAccessToken;
    }

    public function invalidate(string $accessToken): void
    {
        if ($this->cachedAccessToken !== $accessToken) {
            return;
        }

        $this->cachedAccessToken = null;
        $this->refreshAt = null;
    }

    private function assertCredentials(): void
    {
        if (
            preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientId) !== 1
            || preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientSecret) !== 1
        ) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }
    }
}
