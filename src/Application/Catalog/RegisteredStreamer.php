<?php

declare(strict_types=1);

namespace App\Application\Catalog;

final readonly class RegisteredStreamer
{
    public function __construct(
        public string $streamerId,
        public string $platformAccountId,
    ) {
    }
}
