<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

use DateTimeImmutable;

final readonly class TwitchStreamStatus
{
    public function __construct(
        public string $userId,
        public bool $isLive,
        public ?string $streamId,
        public ?string $title,
        public ?DateTimeImmutable $startedAt,
        public ?string $thumbnailUrl,
    ) {
    }
}
