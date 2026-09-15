<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrinePlatformApiCredentialRepository implements PlatformApiCredentialRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByPlatform(Platform $platform): ?PlatformApiCredential
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, platform_code, encrypted_value, encryption_nonce, encryption_key_id, encryption_format_version, created_at, updated_at FROM platform_api_credentials WHERE platform_code = ?',
            [$platform->value],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function save(PlatformApiCredential $credential): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_api_credentials (id, platform_code, encrypted_value, encryption_nonce, encryption_key_id, encryption_format_version, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE encrypted_value = VALUES(encrypted_value), encryption_nonce = VALUES(encryption_nonce), encryption_key_id = VALUES(encryption_key_id), encryption_format_version = VALUES(encryption_format_version), updated_at = VALUES(updated_at)
                SQL,
            [Uuid::fromString($credential->id)->toBinary(), $credential->platform->value, $credential->encryptedSecret->encryptedValue, $credential->encryptedSecret->nonce, $credential->encryptedSecret->keyId, $credential->encryptedSecret->formatVersion, self::formatDateTime($credential->createdAt), self::formatDateTime($credential->updatedAt)],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PlatformApiCredential
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['platform_code'] ?? null) || !is_string($row['encrypted_value'] ?? null) || !is_string($row['encryption_nonce'] ?? null) || !is_string($row['encryption_key_id'] ?? null) || !is_string($row['created_at'] ?? null) || !is_string($row['updated_at'] ?? null)) {
            throw new UnexpectedValueException('プラットフォームAPI資格情報の永続データ形式が不正です。');
        }
        try {
            $platform = Platform::from($row['platform_code']);
            $createdAt = new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC'));
            $updatedAt = new DateTimeImmutable($row['updated_at'], new DateTimeZone('UTC'));
        } catch (\ValueError|\Exception) {
            throw new UnexpectedValueException('プラットフォームAPI資格情報の永続データ形式が不正です。');
        }
        $version = $row['encryption_format_version'] ?? null;
        if ((!is_int($version) && !is_string($version)) || filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new UnexpectedValueException('プラットフォームAPI資格情報の永続データ形式が不正です。');
        }

        return new PlatformApiCredential(Uuid::fromBinary($row['id'])->toRfc4122(), $platform, new EncryptedSecret($row['encrypted_value'], $row['encryption_nonce'], $row['encryption_key_id'], (int) $version), $createdAt, $updatedAt);
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
