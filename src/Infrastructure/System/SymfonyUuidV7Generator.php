<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\System\IdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyUuidV7Generator implements IdGenerator
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
