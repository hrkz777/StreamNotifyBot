<?php

declare(strict_types=1);

namespace App\Domain\Security;

use RuntimeException;

final class SecretDecryptionFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('秘密情報を復号できません。');
    }
}
