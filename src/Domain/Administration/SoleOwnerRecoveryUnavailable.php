<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class SoleOwnerRecoveryUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('資格情報を回復できる単独の有効なownerが存在しません。');
    }
}
