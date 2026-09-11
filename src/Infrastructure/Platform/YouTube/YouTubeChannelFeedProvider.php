<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

interface YouTubeChannelFeedProvider
{
    /** @return list<YouTubeAtomFeedEntry> */
    public function fetch(string $channelId): array;
}
