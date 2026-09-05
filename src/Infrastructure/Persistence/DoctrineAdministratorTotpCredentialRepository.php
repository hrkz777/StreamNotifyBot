<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineAdministratorTotpCredentialRepository implements AdministratorTotpCredentialRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(AdministratorTotpCredential $credential): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_totp_credentials (
                    administrator_id,
                    encrypted_value,
                    encryption_nonce,
                    encryption_key_id,
                    encryption_format_version,
                    last_accepted_time_step
                ) VALUES (?, ?, ?, ?, ?, ?)
                SQL,
            [
                Uuid::fromString($credential->administratorId)->toBinary(),
                $credential->encryptedSecret->encryptedValue,
                $credential->encryptedSecret->nonce,
                $credential->encryptedSecret->keyId,
                $credential->encryptedSecret->formatVersion,
                $credential->lastAcceptedTimeStep,
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ],
        );
    }

    public function findByAdministratorId(string $administratorId): ?AdministratorTotpCredential
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    administrator_id,
                    encrypted_value,
                    encryption_nonce,
                    encryption_key_id,
                    encryption_format_version,
                    last_accepted_time_step
                FROM administrator_totp_credentials
                WHERE administrator_id = ?
                SQL,
            [Uuid::fromString($administratorId)->toBinary()],
            [ParameterType::BINARY],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function acceptTimeStep(string $administratorId, int $timeStep): bool
    {
        if ($timeStep < 0) {
            throw new InvalidArgumentException('受理するTOTP時刻ステップは0以上で指定してください。');
        }

        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE administrator_totp_credentials
                SET last_accepted_time_step = ?
                WHERE administrator_id = ?
                  AND (last_accepted_time_step IS NULL OR last_accepted_time_step < ?)
                SQL,
            [
                $timeStep,
                Uuid::fromString($administratorId)->toBinary(),
                $timeStep,
            ],
            [ParameterType::INTEGER, ParameterType::BINARY, ParameterType::INTEGER],
        );

        return $affectedRows === 1;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AdministratorTotpCredential
    {
        if (
            !is_string($row['administrator_id'] ?? null)
            || !is_string($row['encrypted_value'] ?? null)
            || !is_string($row['encryption_nonce'] ?? null)
            || !is_string($row['encryption_key_id'] ?? null)
            || !array_key_exists('last_accepted_time_step', $row)
        ) {
            throw new UnexpectedValueException('管理者TOTP資格情報の永続データ形式が不正です。');
        }

        return new AdministratorTotpCredential(
            Uuid::fromBinary($row['administrator_id'])->toRfc4122(),
            new EncryptedSecret(
                $row['encrypted_value'],
                $row['encryption_nonce'],
                $row['encryption_key_id'],
                self::readUnsignedInteger($row, 'encryption_format_version'),
            ),
            self::readNullableUnsignedInteger($row, 'last_accepted_time_step'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function readUnsignedInteger(array $row, string $key): int
    {
        $value = self::readNullableUnsignedInteger($row, $key);

        return $value ?? throw new UnexpectedValueException(sprintf('管理者TOTP資格情報の%sがありません。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readNullableUnsignedInteger(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException(sprintf('管理者TOTP資格情報の%sがありません。', $key));
        }

        $value = $row[$key];
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }

        throw new UnexpectedValueException(sprintf('管理者TOTP資格情報の%sが不正です。', $key));
    }
}
