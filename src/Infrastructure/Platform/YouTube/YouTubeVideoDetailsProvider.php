<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

interface YouTubeVideoDetailsProvider
{
    /**
     * @param list<mixed> $videoIds
     * @return list<YouTubeVideoDetails>
     */
    public function fetch(array $videoIds): array;
}
