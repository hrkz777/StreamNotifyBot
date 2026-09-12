<?php

declare(strict_types=1);

namespace App\Domain\System;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OperationalSetting
{
    public function __construct(public string $key, public int $value, public DateTimeImmutable $updatedAt, public int $lockVersion)
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,127}$/D', $key) !== 1 || $value < 0 || $value > 2147483647 || $lockVersion < 0) {
            throw new InvalidArgumentException('運用設定の値が不正です。');
        }
    }
}
