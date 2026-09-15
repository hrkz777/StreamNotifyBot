<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use InvalidArgumentException;
use JsonException;

final readonly class SavePlatformApiCredential
{
    public function __construct(
        private PlatformApiCredentialRepository $repository,
        private SecretCipher $secretCipher,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function save(Platform $platform, array $values): void
    {
        $normalized = $this->normalize($platform, $values);
        try {
            $plainValue = json_encode($normalized, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('API資格情報を保存できませんでした。入力内容を確認してください。');
        }

        try {
            $existing = $this->repository->findByPlatform($platform);
            $now = $this->clock->now();
            $id = $existing === null ? $this->idGenerator->generate() : $existing->id;
            $this->repository->save(new PlatformApiCredential(
                $id,
                $platform,
                $this->secretCipher->encrypt($plainValue, SecretPurpose::PlatformApiCredential, $id),
                $existing === null ? $now : $existing->createdAt,
                $now,
            ));
        } finally {
            sodium_memzero($plainValue);
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function normalize(Platform $platform, array $values): array
    {
        return match ($platform) {
            Platform::YouTube => ['api_key' => $this->requireAsciiValue($values, 'api_key', 'YouTube APIキー')],
            Platform::Twitch => [
                'client_id' => $this->requireAsciiValue($values, 'client_id', 'Twitch Client ID'),
                'client_secret' => $this->requireAsciiValue($values, 'client_secret', 'Twitch Client Secret'),
            ],
            Platform::TwitCasting => [
                'client_id' => $this->requireAsciiValue($values, 'client_id', 'TwitCasting Client ID'),
                'client_secret' => $this->requireAsciiValue($values, 'client_secret', 'TwitCasting Client Secret'),
            ],
        };
    }

    /** @param array<string, mixed> $values */
    private function requireAsciiValue(array $values, string $key, string $label): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || preg_match('/^[\x21-\x7E]{1,255}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは1文字以上255文字以下の半角印字可能文字で入力してください。', $label));
        }

        return $value;
    }
}
