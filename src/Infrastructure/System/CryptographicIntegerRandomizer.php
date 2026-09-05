<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\System\IntegerRandomizer;

final readonly class CryptographicIntegerRandomizer implements IntegerRandomizer
{
    public function between(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
