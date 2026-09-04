<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;
use ValueError;

final readonly class DoctrineAdministratorRepository implements AdministratorRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(Administrator $administrator): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrators (
                    id,
                    login_id,
                    display_name,
                    role,
                    status,
                    password_hash,
                    authentication_version,
                    password_changed_at,
                    totp_enrolled_at,
                    last_login_at,
                    disabled_at,
                    deleted_at,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL,
            [
                Uuid::fromString($administrator->id)->toBinary(),
                $administrator->loginId,
                $administrator->displayName,
                $administrator->role->value,
                $administrator->status->value,
                $administrator->passwordHash,
                $administrator->authenticationVersion,
                self::formatDateTime($administrator->passwordChangedAt),
                self::formatDateTime($administrator->totpEnrolledAt),
                self::formatDateTime($administrator->lastLoginAt),
                self::formatDateTime($administrator->disabledAt),
                self::formatDateTime($administrator->deletedAt),
                self::formatDateTime($administrator->createdAt),
                self::formatDateTime($administrator->updatedAt),
                $administrator->lockVersion,
            ],
            [
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );
    }

    public function findById(string $id): ?Administrator
    {
        $row = $this->connection->fetchAssociative(
            self::selectSql().' WHERE id = ?',
            [Uuid::fromString($id)->toBinary()],
            [ParameterType::BINARY],
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function findByLoginId(string $loginId): ?Administrator
    {
        $row = $this->connection->fetchAssociative(
            self::selectSql().' WHERE login_id = ?',
            [strtolower(trim($loginId))],
            [ParameterType::STRING],
        );

        return $row === false ? null : self::hydrate($row);
    }

    private static function selectSql(): string
    {
        return <<<'SQL'
            SELECT
                id,
                login_id,
                display_name,
                role,
                status,
                password_hash,
                authentication_version,
                password_changed_at,
                totp_enrolled_at,
                last_login_at,
                disabled_at,
                deleted_at,
                created_at,
                updated_at,
                lock_version
            FROM administrators
            SQL;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Administrator
    {
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['login_id'] ?? null)
            || !is_string($row['display_name'] ?? null)
            || !is_string($row['role'] ?? null)
            || !is_string($row['status'] ?? null)
            || !array_key_exists('password_hash', $row)
            || (!is_string($row['password_hash']) && $row['password_hash'] !== null)
        ) {
            throw new UnexpectedValueException('管理者の永続データ形式が不正です。');
        }

        try {
            $role = AdministratorRole::from($row['role']);
            $status = AdministratorStatus::from($row['status']);
        } catch (ValueError $exception) {
            throw new UnexpectedValueException('管理者の権限または状態が不正です。', previous: $exception);
        }

        return new Administrator(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            $row['login_id'],
            $row['display_name'],
            $role,
            $status,
            $row['password_hash'],
            self::readUnsignedInteger($row, 'authentication_version'),
            self::readNullableDateTime($row, 'password_changed_at'),
            self::readNullableDateTime($row, 'totp_enrolled_at'),
            self::readNullableDateTime($row, 'last_login_at'),
            self::readNullableDateTime($row, 'disabled_at'),
            self::readNullableDateTime($row, 'deleted_at'),
            self::readDateTime($row, 'created_at'),
            self::readDateTime($row, 'updated_at'),
            self::readUnsignedInteger($row, 'lock_version'),
        );
    }

    private static function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $row */
    private static function readUnsignedInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            return intval($value);
        }

        throw new UnexpectedValueException(sprintf('管理者の%sが不正です。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readDateTime(array $row, string $key): DateTimeImmutable
    {
        $dateTime = self::readNullableDateTime($row, $key);

        return $dateTime ?? throw new UnexpectedValueException(sprintf('管理者の%sがありません。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readNullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException(sprintf('管理者の%sがありません。', $key));
        }

        $value = $row[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('管理者の%sが不正です。', $key));
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new UnexpectedValueException(sprintf('管理者の%sが不正です。', $key));
        }

        return $dateTime;
    }
}
