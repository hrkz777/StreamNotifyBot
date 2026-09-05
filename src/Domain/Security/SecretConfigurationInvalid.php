<?php

declare(strict_types=1);

namespace App\Domain\Security;

use RuntimeException;

final class SecretConfigurationInvalid extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('秘密情報の暗号化設定が不正です。');
    }
}
