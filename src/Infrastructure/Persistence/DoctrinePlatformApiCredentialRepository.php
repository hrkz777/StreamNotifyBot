<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\System\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrinePlatformApiCredentialRepository implements PlatformApiCredentialRepository
{
    public function __construct(private Connection $connection, private Clock $clock)
    {
    }

    public function findByPlatform(Platform $platform): ?PlatformApiCredential
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, platform_code, encrypted_value, encryption_nonce, encryption_key_id, encryption_format_version FROM platform_api_credentials WHERE platform_code = ?',
            [$platform->value],
            [ParameterType::STRING],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function save(PlatformApiCredential $credential): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_api_credentials (
                    id, platform_code, encrypted_value, encryption_nonce, encryption_key_id,
                    encryption_format_version, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id = VALUES(id), encrypted_value = VALUES(encrypted_value), encryption_nonce = VALUES(encryption_nonce),
                    encryption_key_id = VALUES(encryption_key_id), encryption_format_version = VALUES(encryption_format_version),
                    updated_at = VALUES(updated_at), lock_version = lock_version + 1
                SQL,
            [
                Uuid::fromString($credential->id)->toBinary(),
                $credential->platform->value,
                $credential->encryptedValue->encryptedValue,
                $credential->encryptedValue->nonce,
                $credential->encryptedValue->keyId,
                $credential->encryptedValue->formatVersion,
                $now,
                $now,
            ],
            [
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PlatformApiCredential
    {
        foreach (['id', 'platform_code', 'encrypted_value', 'encryption_nonce', 'encryption_key_id'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new UnexpectedValueException('プラットフォームAPI接続情報の永続データ形式が不正です。');
            }
        }
        $formatVersion = $row['encryption_format_version'] ?? null;
        if (!is_int($formatVersion) && !(is_string($formatVersion) && ctype_digit($formatVersion))) {
            throw new UnexpectedValueException('プラットフォームAPI接続情報の永続データ形式が不正です。');
        }

        return new PlatformApiCredential(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            Platform::from($row['platform_code']),
            new EncryptedSecret($row['encrypted_value'], $row['encryption_nonce'], $row['encryption_key_id'], (int) $formatVersion),
        );
    }
}
