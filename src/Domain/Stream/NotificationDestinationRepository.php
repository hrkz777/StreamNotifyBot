<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface NotificationDestinationRepository
{
    /** @return list<NotificationDestination> */
    public function findEnabledByType(StreamNotificationType $type): array;
}
