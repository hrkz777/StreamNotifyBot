<?php

declare(strict_types=1);

namespace App\Domain\System;

interface IntegerRandomizer
{
    public function between(int $minimum, int $maximum): int;
}
