<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class PlatformAccountIntegrationNotConfigured extends RuntimeException
{
    public function __construct(Platform $platform)
    {
        parent::__construct(sprintf('%sの接続設定が未完了です。プラットフォーム設定でAPI資格情報を登録してください。', $platform->displayId()));
    }
}
