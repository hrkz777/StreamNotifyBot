<?php

declare(strict_types=1);

namespace App\Domain\System;

interface InteractiveTerminal
{
    public function hasInteractiveInput(): bool;

    public function hasInteractiveOutput(): bool;
}
