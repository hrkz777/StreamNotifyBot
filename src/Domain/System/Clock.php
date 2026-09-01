<?php

declare(strict_types=1);

namespace App\Domain\System;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
