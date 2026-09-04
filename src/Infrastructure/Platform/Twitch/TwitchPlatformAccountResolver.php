<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\PlatformAccountResolver;
use App\Domain\Catalog\ResolvedPlatformAccount;
use InvalidArgumentException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwitchPlatformAccountResolver implements PlatformAccountResolver
{
    private const ENDPOINT = 'https://api.twitch.tv/helix/users';
    private const LOGIN_PATTERN = '/^[A-Za-z0-9_]{1,25}$/D';

    public function __construct(
        private HttpClientInterface $httpClient,
        private TwitchAccessTokenProvider $accessTokenProvider,
        private string $clientId,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::Twitch;
    }

    public function resolve(string $registrationIdentifier): ResolvedPlatformAccount
    {
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientId) !== 1) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        $login = $this->parseRegistrationIdentifier($registrationIdentifier);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $accessToken = $this->accessTokenProvider->accessToken();
            [$statusCode, $content] = $this->requestUser($login, $accessToken);
            if ($statusCode !== 401) {
                return $this->resolveResponse($statusCode, $content);
            }

            $this->accessTokenProvider->invalidate($accessToken);
        }

        throw new PlatformAccountResolutionFailed(Platform::Twitch);
    }

    /** @return array{int, string} */
    private function requestUser(string $login, string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$accessToken,
                    'Client-Id' => $this->clientId,
                ],
                'query' => ['login' => $login],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }
    }

    private function resolveResponse(int $statusCode, string $content): ResolvedPlatformAccount
    {
        if ($statusCode !== 200) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        $user = $decoded['data'][0] ?? null;
        if ($user === null) {
            throw new PlatformAccountNotFound(Platform::Twitch);
        }

        if (
            !is_array($user)
            || !is_string($user['id'] ?? null)
            || !is_string($user['login'] ?? null)
            || !is_string($user['display_name'] ?? null)
            || preg_match('/^[\x21-\x7E]{1,255}$/D', $user['id']) !== 1
            || preg_match(self::LOGIN_PATTERN, $user['login']) !== 1
        ) {
            throw new PlatformAccountResolutionFailed(Platform::Twitch);
        }

        $login = strtolower($user['login']);

        return new ResolvedPlatformAccount(
            $user['id'],
            $login,
            $user['display_name'] === '' ? null : $user['display_name'],
            'https://www.twitch.tv/'.$login,
            $this->optionalString($user['profile_image_url'] ?? null),
            $this->optionalString($user['offline_image_url'] ?? null),
            null,
        );
    }

    private function parseRegistrationIdentifier(string $registrationIdentifier): string
    {
        if (preg_match(self::LOGIN_PATTERN, $registrationIdentifier) === 1) {
            return strtolower($registrationIdentifier);
        }

        $parts = parse_url($registrationIdentifier);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new InvalidArgumentException('Twitchの登録識別子はログイン名またはチャンネルURLで指定してください。');
        }

        $segments = array_values(array_filter(
            explode('/', trim($parts['path'], '/')),
            static fn (string $value): bool => $value !== '',
        ));
        if (
            strtolower($parts['scheme']) !== 'https'
            || !in_array(strtolower($parts['host']), ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || count($segments) !== 1
            || preg_match(self::LOGIN_PATTERN, $segments[0]) !== 1
        ) {
            throw new InvalidArgumentException('TwitchのチャンネルURLの形式が不正です。');
        }

        return strtolower($segments[0]);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
