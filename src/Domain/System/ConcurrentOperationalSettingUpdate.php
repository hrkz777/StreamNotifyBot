<?php

declare(strict_types=1);

namespace App\Domain\System;

use RuntimeException;

final class ConcurrentOperationalSettingUpdate extends RuntimeException
{
}
