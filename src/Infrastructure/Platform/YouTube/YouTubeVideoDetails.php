<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use DateTimeImmutable;

final readonly class YouTubeVideoDetails
{
    public function __construct(
        public string $videoId,
        public string $channelId,
        public string $title,
        public DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $scheduledStartAt,
        public ?DateTimeImmutable $actualStartAt,
        public ?DateTimeImmutable $actualEndAt,
        public ?string $thumbnailUrl,
        public string $liveBroadcastContent,
    ) {
    }
}
