<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\System\LeaseTokenGenerator;

final readonly class CryptographicLeaseTokenGenerator implements LeaseTokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
