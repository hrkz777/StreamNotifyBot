<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface StreamNotificationOutboxRepository
{
    public function enqueue(StreamNotificationOutbox $notification): void;

    /** @return list<StreamNotificationOutboxLease> */
    public function claimPending(int $limit, string $leaseToken, int $leaseSeconds): array;

    public function markSent(StreamNotificationOutboxLease $lease): bool;

    public function releaseClaim(StreamNotificationOutboxLease $lease): bool;
}
