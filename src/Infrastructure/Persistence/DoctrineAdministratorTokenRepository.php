<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\AdministratorTokenRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;
use ValueError;

final readonly class DoctrineAdministratorTokenRepository implements AdministratorTokenRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(AdministratorToken $token): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_tokens (
                    id,
                    administrator_id,
                    purpose,
                    token_hash,
                    created_by_administrator_id,
                    created_at,
                    expires_at,
                    consumed_at,
                    revoked_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL,
            [
                Uuid::fromString($token->id)->toBinary(),
                self::nullableUuidToBinary($token->administratorId),
                $token->purpose->value,
                self::hashToBinary($token->tokenHash),
                self::nullableUuidToBinary($token->createdByAdministratorId),
                self::formatDateTime($token->createdAt),
                self::formatDateTime($token->expiresAt),
                self::formatDateTime($token->consumedAt),
                self::formatDateTime($token->revokedAt),
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    public function consumeByHash(
        string $tokenHash,
        AdministratorTokenPurpose $purpose,
        DateTimeImmutable $consumedAt,
    ): ?AdministratorToken {
        $binaryHash = self::hashToBinary($tokenHash);

        return $this->connection->transactional(
            function (Connection $connection) use ($binaryHash, $purpose, $consumedAt): ?AdministratorToken {
                $row = $connection->fetchAssociative(
                    self::selectSql().' WHERE token_hash = ? AND purpose = ? FOR UPDATE',
                    [$binaryHash, $purpose->value],
                    [ParameterType::BINARY, ParameterType::STRING],
                );

                if ($row === false) {
                    return null;
                }

                $token = self::hydrate($row);
                if (!$token->isAvailableAt($consumedAt)) {
                    return null;
                }

                $affectedRows = $connection->executeStatement(
                    <<<'SQL'
                        UPDATE administrator_tokens
                        SET consumed_at = ?
                        WHERE id = ?
                          AND consumed_at IS NULL
                          AND revoked_at IS NULL
                          AND created_at <= ?
                          AND expires_at > ?
                        SQL,
                    [
                        self::formatDateTime($consumedAt),
                        Uuid::fromString($token->id)->toBinary(),
                        self::formatDateTime($consumedAt),
                        self::formatDateTime($consumedAt),
                    ],
                    [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
                );

                if ($affectedRows !== 1) {
                    throw new RuntimeException('管理者トークンの消費状態を更新できませんでした。');
                }

                return new AdministratorToken(
                    $token->id,
                    $token->administratorId,
                    $token->purpose,
                    $token->tokenHash,
                    $token->createdByAdministratorId,
                    $token->createdAt,
                    $token->expiresAt,
                    $consumedAt,
                    $token->revokedAt,
                );
            },
        );
    }

    private static function selectSql(): string
    {
        return <<<'SQL'
            SELECT
                id,
                administrator_id,
                purpose,
                token_hash,
                created_by_administrator_id,
                created_at,
                expires_at,
                consumed_at,
                revoked_at
            FROM administrator_tokens
            SQL;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AdministratorToken
    {
        if (
            !is_string($row['id'] ?? null)
            || !array_key_exists('administrator_id', $row)
            || (!is_string($row['administrator_id']) && $row['administrator_id'] !== null)
            || !is_string($row['purpose'] ?? null)
            || !is_string($row['token_hash'] ?? null)
            || !array_key_exists('created_by_administrator_id', $row)
            || (!is_string($row['created_by_administrator_id']) && $row['created_by_administrator_id'] !== null)
        ) {
            throw new UnexpectedValueException('管理者トークンの永続データ形式が不正です。');
        }

        try {
            $purpose = AdministratorTokenPurpose::from($row['purpose']);
        } catch (ValueError $exception) {
            throw new UnexpectedValueException('管理者トークンの用途が不正です。', previous: $exception);
        }

        return new AdministratorToken(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            self::nullableUuidFromBinary($row['administrator_id']),
            $purpose,
            bin2hex($row['token_hash']),
            self::nullableUuidFromBinary($row['created_by_administrator_id']),
            self::readDateTime($row, 'created_at'),
            self::readDateTime($row, 'expires_at'),
            self::readNullableDateTime($row, 'consumed_at'),
            self::readNullableDateTime($row, 'revoked_at'),
        );
    }

    private static function hashToBinary(string $tokenHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('トークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        $binaryHash = hex2bin($tokenHash);

        return $binaryHash !== false
            ? $binaryHash
            : throw new InvalidArgumentException('トークンハッシュを変換できません。');
    }

    private static function nullableUuidToBinary(?string $id): ?string
    {
        return $id === null ? null : Uuid::fromString($id)->toBinary();
    }

    private static function nullableUuidFromBinary(?string $id): ?string
    {
        return $id === null ? null : Uuid::fromBinary($id)->toRfc4122();
    }

    private static function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $row */
    private static function readDateTime(array $row, string $key): DateTimeImmutable
    {
        $dateTime = self::readNullableDateTime($row, $key);

        return $dateTime ?? throw new UnexpectedValueException(sprintf('管理者トークンの%sがありません。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readNullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException(sprintf('管理者トークンの%sがありません。', $key));
        }

        $value = $row[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('管理者トークンの%sが不正です。', $key));
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new UnexpectedValueException(sprintf('管理者トークンの%sが不正です。', $key));
        }

        return $dateTime;
    }
}
