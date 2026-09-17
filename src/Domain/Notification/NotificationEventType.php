<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum NotificationEventType: string
{
    case Video = 'video';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Ended = 'ended';
}
