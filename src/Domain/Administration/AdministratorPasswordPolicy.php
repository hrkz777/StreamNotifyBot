<?php

declare(strict_types=1);

namespace App\Domain\Administration;

final readonly class AdministratorPasswordPolicy
{
    public const int MINIMUM_LENGTH = 12;
    public const int MAXIMUM_LENGTH = 128;

    public function __construct(private CommonPasswordChecker $commonPasswordChecker)
    {
    }

    /** @throws AdministratorPasswordRejected */
    public function assertAcceptable(string $password): void
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MINIMUM_LENGTH) {
            throw AdministratorPasswordRejected::tooShort();
        }

        if ($length > self::MAXIMUM_LENGTH) {
            throw AdministratorPasswordRejected::tooLong();
        }

        if ($this->commonPasswordChecker->isCommon($password)) {
            throw AdministratorPasswordRejected::common();
        }
    }
}
