<?php

declare(strict_types=1);

namespace App\Domain\Administration;

enum AdministratorPasswordRejection: string
{
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    case Common = 'common';
}
