<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class ConcurrentAdministratorTokenIssuance extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('管理者の認証版が変更されたためトークンを発行できません。');
    }
}
