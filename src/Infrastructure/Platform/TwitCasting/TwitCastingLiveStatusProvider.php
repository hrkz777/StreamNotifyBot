<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\TwitCasting;

interface TwitCastingLiveStatusProvider
{
    public function fetch(string $userId): TwitCastingLiveStatus;
}
