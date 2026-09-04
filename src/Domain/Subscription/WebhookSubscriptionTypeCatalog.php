<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Domain\Catalog\Platform;

final class WebhookSubscriptionTypeCatalog
{
    /** @return non-empty-list<string> */
    public static function forPlatform(Platform $platform): array
    {
        return match ($platform) {
            Platform::YouTube => [
                'channel.feed',
            ],
            Platform::Twitch => [
                'stream.online',
                'stream.offline',
                'channel.update',
            ],
            Platform::TwitCasting => [
                'livestart',
                'liveend',
                'liveschedulecreate',
                'livescheduleupdate',
                'livescheduledelete',
            ],
        };
    }
}
