<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum Platform: string
{
    case YouTube = 'youtube';
    case Twitch = 'twitch';
    case TwitCasting = 'twitcasting';

    public function displayId(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Twitch => 'Twitch',
            self::TwitCasting => 'TwitCasting',
        };
    }
}
