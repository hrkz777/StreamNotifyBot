<?php

declare(strict_types=1);

namespace App\Domain\Administration;

enum AdministratorStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
}
