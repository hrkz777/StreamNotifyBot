<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Security\EncryptedSecret;
use DateTimeImmutable;

final readonly class PlatformApiCredential
{
    public function __construct(
        public string $id,
        public Platform $platform,
        public EncryptedSecret $encryptedSecret,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
