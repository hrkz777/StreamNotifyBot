<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorSessionRepository
{
    public function start(AdministratorSession $session): void;

    public function touch(
        string $tokenHash,
        string $administratorId,
        int $authenticationVersion,
        DateTimeImmutable $now,
        DateTimeImmutable $idleExpiresAt,
    ): bool;

    public function markReauthenticated(
        string $tokenHash,
        string $administratorId,
        int $authenticationVersion,
        DateTimeImmutable $reauthenticatedAt,
    ): bool;

    public function isReauthenticatedSince(
        string $tokenHash,
        string $administratorId,
        int $authenticationVersion,
        DateTimeImmutable $requiredSince,
        DateTimeImmutable $now,
    ): bool;

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): void;
}
