<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface StreamNotificationOutboxRepository
{
    public function enqueue(StreamNotificationOutbox $notification): void;
}
