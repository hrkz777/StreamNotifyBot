<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AuditLogRepository
{
    public function append(AuditLog $auditLog): void;

    /** @return list<AuditLogEntry> */
    public function findLatest(int $limit): array;

    public function deleteBefore(DateTimeImmutable $before): int;
}
