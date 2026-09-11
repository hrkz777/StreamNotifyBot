<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stream;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\StreamNotificationSchedule;
use App\Domain\Stream\StreamNotificationType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamNotificationScheduleTest extends TestCase
{
    #[Test]
    public function itSelectsTheMostRelevantUpcomingNotification(): void
    {
        $schedule = new StreamNotificationSchedule();
        self::assertSame([StreamNotificationType::WaitingRoomCreated], $schedule->due($this->video('upcoming', '2026-09-09T02:00:00Z'), new DateTimeImmutable('2026-09-09T01:00:00Z')));
        self::assertSame([StreamNotificationType::StartingInThirtyMinutes], $schedule->due($this->video('upcoming', '2026-09-09T02:00:00Z'), new DateTimeImmutable('2026-09-09T01:30:00Z')));
        self::assertSame([StreamNotificationType::ScheduledStart], $schedule->due($this->video('upcoming', '2026-09-09T02:00:00Z'), new DateTimeImmutable('2026-09-09T02:00:00Z')));
    }

    private function video(string $state, string $scheduledAt): PlatformVideo
    {
        return new PlatformVideo('01990d4a-0000-7000-8000-000000000801', '01990d4a-0000-7000-8000-000000000802', 'abcdefghijk', '配信', new DateTimeImmutable('2026-09-09T00:00:00Z'), new DateTimeImmutable($scheduledAt), null, null, null, $state, new DateTimeImmutable('2026-09-09T00:00:00Z'));
    }
}
