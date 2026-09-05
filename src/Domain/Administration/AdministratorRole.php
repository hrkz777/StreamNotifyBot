<?php

declare(strict_types=1);

namespace App\Domain\Administration;

enum AdministratorRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
}
