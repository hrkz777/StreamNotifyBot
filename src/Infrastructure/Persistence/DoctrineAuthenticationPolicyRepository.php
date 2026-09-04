<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyNotFound;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\Administration\ConcurrentAuthenticationPolicyUpdate;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineAuthenticationPolicyRepository implements AuthenticationPolicyRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(): AuthenticationPolicy
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id,
                    idle_timeout_minutes,
                    absolute_timeout_hours,
                    reauthentication_minutes,
                    failure_window_minutes,
                    failure_threshold,
                    maximum_delay_minutes,
                    initial_setup_completed_at,
                    updated_at,
                    lock_version
                FROM authentication_policies
                WHERE id = ?
                SQL,
            [Uuid::fromString(AuthenticationPolicy::ID)->toBinary()],
            [ParameterType::BINARY],
        );

        if ($row === false) {
            throw new AuthenticationPolicyNotFound();
        }

        return self::hydrate($row);
    }

    public function save(AuthenticationPolicy $policy): void
    {
        $initialSetupCompletedAt = self::formatDateTime($policy->initialSetupCompletedAt);
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE authentication_policies
                SET idle_timeout_minutes = ?,
                    absolute_timeout_hours = ?,
                    reauthentication_minutes = ?,
                    failure_window_minutes = ?,
                    failure_threshold = ?,
                    maximum_delay_minutes = ?,
                    initial_setup_completed_at = ?,
                    updated_at = ?,
                    lock_version = lock_version + 1
                WHERE id = ?
                  AND lock_version = ?
                  AND (initial_setup_completed_at IS NULL OR initial_setup_completed_at = ?)
                SQL,
            [
                $policy->idleTimeoutMinutes,
                $policy->absoluteTimeoutHours,
                $policy->reauthenticationMinutes,
                $policy->failureWindowMinutes,
                $policy->failureThreshold,
                $policy->maximumDelayMinutes,
                $initialSetupCompletedAt,
                self::formatDateTime($policy->updatedAt),
                Uuid::fromString($policy->id)->toBinary(),
                $policy->lockVersion,
                $initialSetupCompletedAt,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::INTEGER,
                ParameterType::STRING,
            ],
        );

        if ($affectedRows !== 1) {
            throw new ConcurrentAuthenticationPolicyUpdate();
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AuthenticationPolicy
    {
        if (!is_string($row['id'] ?? null)) {
            throw new UnexpectedValueException('認証方針のIDが不正です。');
        }

        return new AuthenticationPolicy(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            self::readUnsignedInteger($row, 'idle_timeout_minutes'),
            self::readUnsignedInteger($row, 'absolute_timeout_hours'),
            self::readUnsignedInteger($row, 'reauthentication_minutes'),
            self::readUnsignedInteger($row, 'failure_window_minutes'),
            self::readUnsignedInteger($row, 'failure_threshold'),
            self::readUnsignedInteger($row, 'maximum_delay_minutes'),
            self::readNullableDateTime($row, 'initial_setup_completed_at'),
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

        throw new UnexpectedValueException(sprintf('認証方針の%sが不正です。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readDateTime(array $row, string $key): DateTimeImmutable
    {
        $dateTime = self::readNullableDateTime($row, $key);

        return $dateTime ?? throw new UnexpectedValueException(sprintf('認証方針の%sがありません。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readNullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException(sprintf('認証方針の%sがありません。', $key));
        }

        $value = $row[$key];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('認証方針の%sが不正です。', $key));
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new UnexpectedValueException(sprintf('認証方針の%sが不正です。', $key));
        }

        return $dateTime;
    }
}
