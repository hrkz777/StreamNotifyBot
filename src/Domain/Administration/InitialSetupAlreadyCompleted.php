<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class InitialSetupAlreadyCompleted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('初期ownerは既に作成されています。');
    }
}
