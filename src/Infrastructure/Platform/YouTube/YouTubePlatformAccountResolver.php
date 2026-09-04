<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\PlatformAccountResolver;
use App\Domain\Catalog\ResolvedPlatformAccount;
use App\Domain\System\Clock;
use InvalidArgumentException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YouTubePlatformAccountResolver implements PlatformAccountResolver
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/channels';
    private const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/D';
    private const HANDLE_PATTERN = '/^@?[\p{L}\p{M}\p{N}_.\-·]{3,30}$/uD';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Clock $clock,
        private string $apiKey,
    ) {
    }

    public function platform(): Platform
    {
        return Platform::YouTube;
    }

    public function resolve(string $registrationIdentifier): ResolvedPlatformAccount
    {
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $this->apiKey) !== 1) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        [$filter, $identifier] = $this->parseRegistrationIdentifier($registrationIdentifier);

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Goog-Api-Key' => $this->apiKey,
                ],
                'query' => [
                    'part' => 'snippet',
                    'maxResults' => 1,
                    $filter => $identifier,
                ],
                'max_redirects' => 0,
                'timeout' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        if ($statusCode !== 200) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        $item = $decoded['items'][0] ?? null;
        if ($item === null) {
            throw new PlatformAccountNotFound(Platform::YouTube);
        }

        if (!is_array($item) || !is_string($item['id'] ?? null) || !is_array($item['snippet'] ?? null)) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        $externalId = $item['id'];
        $snippet = $item['snippet'];
        if (preg_match(self::CHANNEL_ID_PATTERN, $externalId) !== 1) {
            throw new PlatformAccountResolutionFailed(Platform::YouTube);
        }

        return new ResolvedPlatformAccount(
            $externalId,
            $this->optionalString($snippet['customUrl'] ?? null),
            $this->optionalString($snippet['title'] ?? null),
            sprintf('https://www.youtube.com/channel/%s', $externalId),
            $this->thumbnailUrl($snippet['thumbnails'] ?? null),
            null,
            $this->clock->now()->modify('+30 days'),
        );
    }

    /** @return array{string, string} */
    private function parseRegistrationIdentifier(string $registrationIdentifier): array
    {
        if (preg_match(self::CHANNEL_ID_PATTERN, $registrationIdentifier) === 1) {
            return ['id', $registrationIdentifier];
        }

        if (preg_match(self::HANDLE_PATTERN, $registrationIdentifier) === 1) {
            return ['forHandle', $registrationIdentifier];
        }

        $parts = parse_url($registrationIdentifier);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new InvalidArgumentException('YouTubeの登録識別子はチャンネルID、ハンドル、またはチャンネルURLで指定してください。');
        }

        $host = strtolower($parts['host']);
        if (
            $parts['scheme'] !== 'https'
            || !in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('YouTubeのチャンネルURLの形式が不正です。');
        }

        $segments = array_map(
            rawurldecode(...),
            array_values(array_filter(
                explode('/', trim($parts['path'], '/')),
                static fn (string $value): bool => $value !== '',
            )),
        );
        if (count($segments) !== 1 && count($segments) !== 2) {
            throw new InvalidArgumentException('YouTubeのチャンネルURLの形式が不正です。');
        }

        if (count($segments) === 1 && preg_match(self::HANDLE_PATTERN, $segments[0]) === 1) {
            return ['forHandle', $segments[0]];
        }

        if (count($segments) === 2 && $segments[0] === 'channel' && preg_match(self::CHANNEL_ID_PATTERN, $segments[1]) === 1) {
            return ['id', $segments[1]];
        }

        if (count($segments) === 2 && $segments[0] === 'user') {
            return ['forUsername', $segments[1]];
        }

        throw new InvalidArgumentException('YouTubeのチャンネルURLの形式が不正です。');
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function thumbnailUrl(mixed $thumbnails): ?string
    {
        if (!is_array($thumbnails)) {
            return null;
        }

        foreach (['high', 'medium', 'default'] as $size) {
            $thumbnail = $thumbnails[$size] ?? null;
            if (is_array($thumbnail)) {
                $url = $this->optionalString($thumbnail['url'] ?? null);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }
}
