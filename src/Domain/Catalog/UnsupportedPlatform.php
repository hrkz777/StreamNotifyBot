<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class UnsupportedPlatform extends RuntimeException
{
    public function __construct(Platform $platform)
    {
        parent::__construct(sprintf('プラットフォーム「%s」のAdapterが登録されていません。', $platform->displayId()));
    }
}
