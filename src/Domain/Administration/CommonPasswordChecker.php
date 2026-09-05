<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface CommonPasswordChecker
{
    public function isCommon(string $password): bool;
}
