<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\TwitCasting;

use DateTimeImmutable;

final readonly class TwitCastingLiveStatus
{
    public function __construct(public string $userId, public bool $isLive, public ?string $movieId, public ?string $title, public ?DateTimeImmutable $startedAt)
    {
    }
}
