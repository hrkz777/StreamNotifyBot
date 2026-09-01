<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\TwitCasting;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\PlatformAccountResolver;
use App\Domain\Catalog\ResolvedPlatformAccount;
use InvalidArgumentException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TwitCastingPlatformAccountResolver implements PlatformAccountResolver
{
    private const ENDPOINT = 'https://apiv2.twitcasting.tv/users/';
    private const SCREEN_ID_PATTERN = '/^[A-Za-z0-9_:\-]{1,64}$/D';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::TwitCasting;
    }

    public function resolve(string $registrationIdentifier): ResolvedPlatformAccount
    {
        $this->assertCredentials();
        $screenId = $this->parseRegistrationIdentifier($registrationIdentifier);

        try {
            $response = $this->httpClient->request(
                'GET',
                self::ENDPOINT.rawurlencode($screenId),
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                        'X-Api-Version' => '2.0',
                    ],
                    'max_redirects' => 0,
                    'timeout' => 10.0,
                ],
            );
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }

        if ($statusCode === 404) {
            throw new PlatformAccountNotFound(Platform::TwitCasting);
        }

        if ($statusCode !== 200) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }

        $user = is_array($decoded) ? ($decoded['user'] ?? null) : null;
        if (
            !is_array($user)
            || !is_string($user['id'] ?? null)
            || !is_string($user['screen_id'] ?? null)
            || !is_string($user['name'] ?? null)
            || preg_match('/^[\x21-\x7E]{1,255}$/D', $user['id']) !== 1
            || preg_match(self::SCREEN_ID_PATTERN, $user['screen_id']) !== 1
        ) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }

        return new ResolvedPlatformAccount(
            $user['id'],
            $user['screen_id'],
            $user['name'] === '' ? null : $user['name'],
            'https://twitcasting.tv/'.rawurlencode($user['screen_id']),
            $this->normalizeImageUrl($user['image'] ?? null),
            null,
            null,
        );
    }

    private function parseRegistrationIdentifier(string $registrationIdentifier): string
    {
        if (preg_match(self::SCREEN_ID_PATTERN, $registrationIdentifier) === 1) {
            return $registrationIdentifier;
        }

        $parts = parse_url($registrationIdentifier);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new InvalidArgumentException('TwitCastingの登録識別子はScreen IDまたはユーザーページURLで指定してください。');
        }

        $segments = array_values(array_filter(
            explode('/', trim($parts['path'], '/')),
            static fn (string $value): bool => $value !== '',
        ));
        $host = strtolower($parts['host']);
        if (
            strtolower($parts['scheme']) !== 'https'
            || ($host !== 'twitcasting.tv' && preg_match('/^(?:www|[a-z]{2})\.twitcasting\.tv$/D', $host) !== 1)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || count($segments) !== 1
        ) {
            throw new InvalidArgumentException('TwitCastingのユーザーページURLの形式が不正です。');
        }

        $screenId = rawurldecode($segments[0]);
        if (preg_match(self::SCREEN_ID_PATTERN, $screenId) !== 1) {
            throw new InvalidArgumentException('TwitCastingのユーザーページURLの形式が不正です。');
        }

        return $screenId;
    }

    private function assertCredentials(): void
    {
        if (
            preg_match('/^[A-Za-z0-9._\-]{1,255}$/D', $this->clientId) !== 1
            || preg_match('/^[\x21-\x7E]{1,255}$/D', $this->clientSecret) !== 1
        ) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }
    }

    private function normalizeImageUrl(mixed $imageUrl): ?string
    {
        if (!is_string($imageUrl) || $imageUrl === '') {
            return null;
        }

        $normalizedUrl = str_starts_with($imageUrl, 'http://')
            ? 'https://'.substr($imageUrl, 7)
            : $imageUrl;
        $parts = parse_url($normalizedUrl);
        if (
            filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new PlatformAccountResolutionFailed(Platform::TwitCasting);
        }

        return $normalizedUrl;
    }
}
