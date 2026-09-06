<?php

declare(strict_types=1);

namespace App\Domain\Administration;

enum AuditLogResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
}
