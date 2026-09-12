<?php

declare(strict_types=1);

namespace App\Domain\System;

use InvalidArgumentException;

final readonly class OperationalSettingDefinition
{
    public function __construct(public string $key, public int $minimum, public int $maximum)
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,127}$/D', $key) !== 1 || $minimum < 0 || $maximum < $minimum) {
            throw new InvalidArgumentException('運用設定の定義が不正です。');
        }
    }

    public function accepts(int $value): bool
    {
        return $value >= $this->minimum && $value <= $this->maximum;
    }
}
