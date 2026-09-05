<?php

declare(strict_types=1);

namespace App\Domain\System;

interface LeaseTokenGenerator
{
    public function generate(): string;
}
