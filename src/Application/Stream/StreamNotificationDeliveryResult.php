<?php

declare(strict_types=1);

namespace App\Application\Stream;

final readonly class StreamNotificationDeliveryResult
{
    public function __construct(public int $claimedCount, public int $sentCount, public int $suppressedCount, public int $releasedCount, public int $staleResultCount)
    {
    }
}
