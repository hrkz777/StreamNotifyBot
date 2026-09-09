<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

final readonly class YouTubeAtomFeedEntry
{
    public function __construct(public string $videoId, public string $channelId)
    {
    }
}
