<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Twitch;

interface TwitchAccessTokenProvider
{
    public function accessToken(): string;

    public function invalidate(string $accessToken): void;
}
