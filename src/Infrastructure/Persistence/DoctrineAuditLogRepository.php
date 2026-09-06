<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogRepository;
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
                $auditLog->sourceIp,
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

    private static function nullableUuidToBinary(?string $id): ?string
    {
        return $id === null ? null : Uuid::fromString($id)->toBinary();
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
