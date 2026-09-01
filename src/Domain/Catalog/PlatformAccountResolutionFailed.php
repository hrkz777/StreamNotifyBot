<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class PlatformAccountResolutionFailed extends RuntimeException
{
    public function __construct(Platform $platform)
    {
        parent::__construct(sprintf('%sのアカウント情報を取得できませんでした。', $platform->displayId()));
    }
}
