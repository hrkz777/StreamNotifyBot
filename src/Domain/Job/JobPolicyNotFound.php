<?php

declare(strict_types=1);

namespace App\Domain\Job;

use RuntimeException;

final class JobPolicyNotFound extends RuntimeException
{
    public function __construct(JobType $jobType)
    {
        parent::__construct(sprintf('ジョブ方針が見つかりません: %s', $jobType->value));
    }
}
