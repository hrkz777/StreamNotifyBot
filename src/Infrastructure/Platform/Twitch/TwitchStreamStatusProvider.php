<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

interface TwitchStreamStatusProvider
{
    /**
     * @param list<string> $userIds
     * @return list<TwitchStreamStatus>
     */
    public function fetch(array $userIds): array;
}
