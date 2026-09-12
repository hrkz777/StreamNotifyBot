<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;

interface StreamNotificationOutboxRepository
{
    public function enqueue(StreamNotificationOutbox $notification): void;

    /** @return list<StreamNotificationOutboxLease> */
    public function claimPending(int $limit, string $leaseToken, int $leaseSeconds): array;

    public function markSent(StreamNotificationOutboxLease $lease): bool;

    public function suppress(StreamNotificationOutboxLease $lease): bool;

    public function releaseClaim(StreamNotificationOutboxLease $lease): bool;

    /** @return list<SentStreamNotification> */
    public function findSentSince(DateTimeImmutable $since, int $limit): array;
}
