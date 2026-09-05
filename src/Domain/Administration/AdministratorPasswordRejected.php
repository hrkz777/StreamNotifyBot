<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use InvalidArgumentException;

final class AdministratorPasswordRejected extends InvalidArgumentException
{
    private function __construct(public readonly AdministratorPasswordRejection $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function tooShort(): self
    {
        return new self(
            AdministratorPasswordRejection::TooShort,
            'パスワードは12文字以上で指定してください。',
        );
    }

    public static function tooLong(): self
    {
        return new self(
            AdministratorPasswordRejection::TooLong,
            'パスワードは128文字以下で指定してください。',
        );
    }

    public static function common(): self
    {
        return new self(
            AdministratorPasswordRejection::Common,
            'よく使われるパスワードは使用できません。',
        );
    }
}
