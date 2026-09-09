<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateInterval;
use DateTimeImmutable;

final class StreamNotificationSchedule
{
    /** @return list<StreamNotificationType> */
    public function due(PlatformVideo $video, DateTimeImmutable $now): array
    {
        if ($video->actualEndAt !== null) {
            return [StreamNotificationType::Ended];
        }
        if ($video->actualStartAt !== null || $video->lifecycleState === 'live') {
            return [StreamNotificationType::Started];
        }
        if ($video->lifecycleState === 'upcoming' && $video->scheduledStartAt !== null) {
            if ($now >= $video->scheduledStartAt) {
                return [StreamNotificationType::ScheduledStart];
            }
            if ($now >= $video->scheduledStartAt->sub(new DateInterval('PT30M'))) {
                return [StreamNotificationType::StartingInThirtyMinutes];
            }

            return [StreamNotificationType::WaitingRoomCreated];
        }

        return [StreamNotificationType::VideoPublished];
    }
}
