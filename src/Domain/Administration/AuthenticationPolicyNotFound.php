<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use RuntimeException;

final class AuthenticationPolicyNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('認証方針が見つかりません。');
    }
}
