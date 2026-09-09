<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

final readonly class AuditLogEntry
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $occurredAt,
        public ?string $actorAdministratorId,
        public ?string $actorDisplaySnapshot,
        public string $actionCode,
        public ?string $targetType,
        public ?string $targetId,
        public AuditLogResult $result,
        public string $correlationId,
        public ?string $sourceIp,
        public ?string $userAgent,
        public ?string $changeSummary,
        public ?string $errorCode,
    ) {
    }
}
