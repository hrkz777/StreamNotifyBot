<?php

declare(strict_types=1);

namespace App\Domain\Job;

interface JobPolicyRepository
{
    public function get(JobType $jobType): JobPolicy;

    /** @return list<JobPolicy> */
    public function findAll(): array;

    public function save(JobPolicy $policy): void;
}
