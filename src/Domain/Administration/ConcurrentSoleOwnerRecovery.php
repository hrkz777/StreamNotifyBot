<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class ConcurrentSoleOwnerRecovery extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ownerの状態が変更されたため資格情報を回復できませんでした。');
    }
}
