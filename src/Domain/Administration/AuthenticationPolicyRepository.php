<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AuthenticationPolicyRepository
{
    public function get(): AuthenticationPolicy;

    public function save(AuthenticationPolicy $policy): void;
}
