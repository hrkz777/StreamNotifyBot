<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogEntry;
use App\Domain\Administration\AuditLogRepository;
use App\Domain\Administration\AuditLogResult;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAuditLogRepository implements AuditLogRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditLog $auditLog): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO audit_logs (
                    id,
                    occurred_at,
                    actor_administrator_id,
                    actor_display_snapshot,
                    action_code,
                    target_type,
                    target_id,
                    result,
                    correlation_id,
                    source_ip,
                    user_agent,
                    change_summary,
                    error_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL,
            [
                Uuid::fromString($auditLog->id)->toBinary(),
                self::formatDateTime($auditLog->occurredAt),
                self::nullableUuidToBinary($auditLog->actorAdministratorId),
                $auditLog->actorDisplaySnapshot,
                $auditLog->actionCode,
                $auditLog->targetType,
                $auditLog->targetId,
                $auditLog->result->value,
                $auditLog->correlationId,
                self::normalizeSourceIpForMariaDb($auditLog->sourceIp),
                $auditLog->userAgent,
                $auditLog->changeSummary,
                $auditLog->errorCode,
            ],
            [
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    /** @return list<AuditLogEntry> */
    public function findLatest(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('監査ログ取得件数は1件以上100件以下で指定してください。');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id, occurred_at, actor_administrator_id, actor_display_snapshot, action_code,
                    target_type, target_id, result, correlation_id, source_ip, user_agent,
                    change_summary, error_code
                FROM audit_logs
                ORDER BY occurred_at DESC, id DESC
                LIMIT ?
                SQL,
            [$limit],
            [ParameterType::INTEGER],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function deleteBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM audit_logs WHERE occurred_at < ?',
            [self::formatDateTime($before)],
            [ParameterType::STRING],
        );
    }

    private static function nullableUuidToBinary(?string $id): ?string
    {
        return $id === null ? null : Uuid::fromString($id)->toBinary();
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function normalizeSourceIpForMariaDb(?string $sourceIp): ?string
    {
        if ($sourceIp === null) {
            return null;
        }

        $packedIp = inet_pton($sourceIp);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        $normalizedIp = inet_ntop($packedIp);
        if ($normalizedIp === false) {
            throw new \InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        return strlen($packedIp) === 4
            ? sprintf('::ffff:%s', $normalizedIp)
            : $normalizedIp;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): AuditLogEntry
    {
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['occurred_at'] ?? null)
            || !is_string($row['action_code'] ?? null)
            || !is_string($row['result'] ?? null)
            || !is_string($row['correlation_id'] ?? null)
        ) {
            throw new \UnexpectedValueException('監査ログの永続データ形式が不正です。');
        }

        $occurredAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['occurred_at'], new DateTimeZone('UTC'));
        if ($occurredAt === false) {
            throw new \UnexpectedValueException('監査ログの発生日時が不正です。');
        }

        try {
            $result = AuditLogResult::from($row['result']);
        } catch (\ValueError $exception) {
            throw new \UnexpectedValueException('監査ログの結果が不正です。', previous: $exception);
        }

        return new AuditLogEntry(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            $occurredAt,
            self::nullableBinaryUuid($row, 'actor_administrator_id'),
            self::nullableString($row, 'actor_display_snapshot'),
            $row['action_code'],
            self::nullableString($row, 'target_type'),
            self::nullableString($row, 'target_id'),
            $result,
            $row['correlation_id'],
            self::nullableString($row, 'source_ip'),
            self::nullableString($row, 'user_agent'),
            self::nullableString($row, 'change_summary'),
            self::nullableString($row, 'error_code'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function nullableBinaryUuid(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('監査ログの%sが不正です。', $key));
        }

        return Uuid::fromBinary($value)->toRfc4122();
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException(sprintf('監査ログの%sが不正です。', $key));
    }
}
