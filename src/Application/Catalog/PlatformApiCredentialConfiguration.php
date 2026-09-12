<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use InvalidArgumentException;
use JsonException;

final readonly class PlatformApiCredentialConfiguration
{
    /** @param array<string, string> $values */
    private function __construct(public Platform $platform, private array $values)
    {
    }

    public static function youTube(#[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $webSubSecret): self
    {
        self::assertValue($apiKey, 'YouTube APIキー');
        if (preg_match('/^[\x21-\x7E]{32,199}$/D', $webSubSecret) !== 1) {
            throw new InvalidArgumentException('YouTube WebSubシークレットは空白を含まない32～199文字のASCII文字列で指定してください。');
        }

        return new self(Platform::YouTube, ['api_key' => $apiKey, 'websub_secret' => $webSubSecret]);
    }

    public static function twitch(#[\SensitiveParameter] string $clientId, #[\SensitiveParameter] string $clientSecret): self
    {
        return new self(Platform::Twitch, self::clientCredentials($clientId, $clientSecret, 'Twitch'));
    }

    public static function twitCasting(#[\SensitiveParameter] string $clientId, #[\SensitiveParameter] string $clientSecret): self
    {
        return new self(Platform::TwitCasting, self::clientCredentials($clientId, $clientSecret, 'TwitCasting'));
    }

    public static function fromJson(Platform $platform, #[\SensitiveParameter] string $json): self
    {
        try {
            $values = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('プラットフォームAPI接続情報の形式が不正です。');
        }
        if (!is_array($values) || array_is_list($values) || array_filter($values, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new InvalidArgumentException('プラットフォームAPI接続情報の形式が不正です。');
        }
        /** @var array<string, mixed> $values */

        return match ($platform) {
            Platform::YouTube => self::youTube(self::required($values, 'api_key'), self::required($values, 'websub_secret')),
            Platform::Twitch => self::twitch(self::required($values, 'client_id'), self::required($values, 'client_secret')),
            Platform::TwitCasting => self::twitCasting(self::required($values, 'client_id'), self::required($values, 'client_secret')),
        };
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->values, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('プラットフォームAPI接続情報を保存できません。');
        }
    }

    public function value(string $key): string
    {
        return self::required($this->values, $key);
    }

    /** @return array<string, string> */
    private static function clientCredentials(string $clientId, string $clientSecret, string $platform): array
    {
        self::assertValue($clientId, "{$platform} Client ID");
        self::assertValue($clientSecret, "{$platform} Client Secret");

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    /** @param array<string, mixed> $values */
    private static function required(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('プラットフォームAPI接続情報の形式が不正です。');
        }

        return $value;
    }

    private static function assertValue(string $value, string $label): void
    {
        if ($value === '' || mb_strlen($value, 'UTF-8') > 255) {
            throw new InvalidArgumentException("{$label}は1～255文字で指定してください。");
        }
    }
}
