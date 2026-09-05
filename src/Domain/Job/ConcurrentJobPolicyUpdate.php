<?php

declare(strict_types=1);

namespace App\Domain\Job;

use RuntimeException;

final class ConcurrentJobPolicyUpdate extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ジョブ方針は別の処理によって更新されています。');
    }
}
