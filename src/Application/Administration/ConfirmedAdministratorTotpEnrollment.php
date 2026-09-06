<?php

declare(strict_types=1);

namespace App\Application\Administration;

use InvalidArgumentException;

final readonly class ConfirmedAdministratorTotpEnrollment
{
    /** @param list<string> $recoveryCodes */
    public function __construct(public array $recoveryCodes)
    {
        if (count($recoveryCodes) !== 10) {
            throw new InvalidArgumentException('確認済みTOTP登録には10件の回復コードが必要です。');
        }
    }
}
