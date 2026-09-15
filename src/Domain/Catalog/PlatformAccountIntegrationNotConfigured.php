<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use RuntimeException;

final class PlatformAccountIntegrationNotConfigured extends RuntimeException
{
    public function __construct(Platform $platform, string $settingName)
    {
        parent::__construct(sprintf('%sの接続設定が未完了です。%sを設定し、appコンテナを再ビルドしてください。', $platform->displayId(), $settingName));
    }
}
