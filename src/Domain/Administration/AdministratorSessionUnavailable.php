<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class AdministratorSessionUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('現在の管理者認証状態ではセッションを開始できません。');
    }
}
