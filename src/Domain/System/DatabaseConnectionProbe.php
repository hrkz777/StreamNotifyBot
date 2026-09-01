<?php

declare(strict_types=1);

namespace App\Domain\System;

interface DatabaseConnectionProbe
{
    public function isAvailable(): bool;
}
