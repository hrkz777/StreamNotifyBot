<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class AdministratorAlreadyExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('既存の管理者があるため初期ownerを作成できません。');
    }
}
