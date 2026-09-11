<?php

declare(strict_types=1);

namespace App\Application\Stream;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\Stream\StreamNotificationSchedule;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;

final readonly class EnqueueStreamNotifications
{
    public function __construct(private StreamNotificationSchedule $schedule, private StreamNotificationOutboxRepository $outboxRepository, private IdGenerator $idGenerator, private Clock $clock)
    {
    }

    public function enqueue(string $platformVideoId, PlatformVideo $video): int
    {
        $now = $this->clock->now();
        $types = $this->schedule->due($video, $now);
        foreach ($types as $type) {
            $this->outboxRepository->enqueue(new StreamNotificationOutbox(
                $this->idGenerator->generate(),
                $platformVideoId,
                $type,
                hash('sha256', implode("\0", [$type->value, $video->title, $video->thumbnailUrl ?? '', $video->scheduledStartAt?->format(DATE_ATOM) ?? '', $video->actualStartAt?->format(DATE_ATOM) ?? '', $video->actualEndAt?->format(DATE_ATOM) ?? ''])),
                $now,
            ));
        }

        return count($types);
    }
}
