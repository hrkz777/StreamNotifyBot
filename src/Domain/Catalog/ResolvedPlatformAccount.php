<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use DateTimeImmutable;

final readonly class ResolvedPlatformAccount
{
    public function __construct(
        public string $externalId,
        public ?string $displayId,
        public ?string $name,
        public ?string $profileUrl,
        public ?string $iconUrl,
        public ?string $offlineImageUrl,
        public ?DateTimeImmutable $apiDataExpiresAt,
    ) {
    }
}
