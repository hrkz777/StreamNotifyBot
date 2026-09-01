<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class PlatformAccountNotFound extends RuntimeException
{
    public function __construct(Platform $platform)
    {
        parent::__construct(sprintf('%sのアカウントが見つかりませんでした。', $platform->displayId()));
    }
}
