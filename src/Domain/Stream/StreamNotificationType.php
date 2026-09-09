<?php

declare(strict_types=1);

namespace App\Domain\Stream;

enum StreamNotificationType: string
{
    case VideoPublished = 'video_published';
    case WaitingRoomCreated = 'waiting_room_created';
    case StartingInThirtyMinutes = 'starting_in_thirty_minutes';
    case ScheduledStart = 'scheduled_start';
    case Started = 'started';
    case Ended = 'ended';
}
