<?php

declare(strict_types=1);

namespace App\Application\Subscription;

interface TwitchStreamSynchronizer
{
    public function sync(): int;
}
