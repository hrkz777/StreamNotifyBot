<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Job\ConcurrentJobPolicyUpdate;
use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobPolicyNotFound;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineJobPolicyRepository implements JobPolicyRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(JobType $jobType): JobPolicy
    {
        $row = $this->connection->fetchAssociative(
            self::selectSql().' WHERE job_type = ?',
            [$jobType->value],
            [ParameterType::STRING],
        );

        if ($row === false) {
            throw new JobPolicyNotFound($jobType);
        }

        return self::hydrate($row);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(self::selectSql().' ORDER BY job_type');

        return array_map(self::hydrate(...), $rows);
    }

    public function save(JobPolicy $policy): void
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE job_policies
                SET
                    batch_size = ?,
                    max_runtime_seconds = ?,
                    max_attempts = ?,
                    retry_initial_delay_seconds = ?,
                    retry_max_delay_seconds = ?,
                    backoff_multiplier = ?,
                    jitter_percent = ?,
                    lease_seconds = ?,
                    is_enabled = ?,
                    updated_at = ?,
                    lock_version = lock_version + 1
                WHERE id = ? AND job_type = ? AND lock_version = ?
                SQL,
            [
                $policy->batchSize,
                $policy->maxRuntimeSeconds,
                $policy->maxAttempts,
                $policy->retryInitialDelaySeconds,
                $policy->retryMaxDelaySeconds,
                number_format($policy->backoffMultiplier, 2, '.', ''),
                $policy->jitterPercent,
                $policy->leaseSeconds,
                $policy->isEnabled ? 1 : 0,
                self::formatDateTime($policy->updatedAt),
                Uuid::fromString($policy->id)->toBinary(),
                $policy->jobType->value,
                $policy->lockVersion,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );

        if ($affectedRows !== 1) {
            throw new ConcurrentJobPolicyUpdate();
        }
    }

    private static function selectSql(): string
    {
        return <<<'SQL'
            SELECT
                id,
                job_type,
                batch_size,
                max_runtime_seconds,
                max_attempts,
                retry_initial_delay_seconds,
                retry_max_delay_seconds,
                backoff_multiplier,
                jitter_percent,
                lease_seconds,
                is_enabled,
                updated_at,
                lock_version
            FROM job_policies
            SQL;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): JobPolicy
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['job_type'] ?? null)) {
            throw new UnexpectedValueException('ジョブ方針の識別情報が不正です。');
        }

        return new JobPolicy(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            JobType::from($row['job_type']),
            self::readUnsignedInteger($row, 'batch_size'),
            self::readUnsignedInteger($row, 'max_runtime_seconds'),
            self::readUnsignedInteger($row, 'max_attempts'),
            self::readUnsignedInteger($row, 'retry_initial_delay_seconds'),
            self::readUnsignedInteger($row, 'retry_max_delay_seconds'),
            self::readDecimal($row, 'backoff_multiplier'),
            self::readUnsignedInteger($row, 'jitter_percent'),
            self::readUnsignedInteger($row, 'lease_seconds'),
            self::readBoolean($row, 'is_enabled'),
            self::readDateTime($row, 'updated_at'),
            self::readUnsignedInteger($row, 'lock_version'),
        );
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
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

        throw new UnexpectedValueException(sprintf('ジョブ方針の%sが不正です。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readDecimal(array $row, string $key): float
    {
        $value = $row[$key] ?? null;
        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1) {
            return (float) $value;
        }

        throw new UnexpectedValueException(sprintf('ジョブ方針の%sが不正です。', $key));
    }

    /** @param array<string, mixed> $row */
    private static function readBoolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return match ($value) {
            0, '0' => false,
            1, '1' => true,
            default => throw new UnexpectedValueException(sprintf('ジョブ方針の%sが不正です。', $key)),
        };
    }

    /** @param array<string, mixed> $row */
    private static function readDateTime(array $row, string $key): DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('ジョブ方針の%sが不正です。', $key));
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new UnexpectedValueException(sprintf('ジョブ方針の%sが不正です。', $key));
        }

        return $dateTime;
    }
}
