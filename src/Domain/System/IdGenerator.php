<?php

declare(strict_types=1);

namespace App\Domain\System;

interface IdGenerator
{
    public function generate(): string;
}
