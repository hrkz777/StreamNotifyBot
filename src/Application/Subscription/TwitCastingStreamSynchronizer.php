<?php

declare(strict_types=1);

namespace App\Application\Subscription;

interface TwitCastingStreamSynchronizer
{
    public function sync(): int;
}
