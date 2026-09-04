<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticationPolicy
{
    public const string ID = '01990d4a-0000-7000-8000-000000000002';

    public function __construct(
        public string $id,
        public int $idleTimeoutMinutes,
        public int $absoluteTimeoutHours,
        public int $reauthenticationMinutes,
        public int $failureWindowMinutes,
        public int $failureThreshold,
        public int $maximumDelayMinutes,
        public ?DateTimeImmutable $initialSetupCompletedAt,
        public DateTimeImmutable $updatedAt,
        public int $lockVersion,
    ) {
        if ($id !== self::ID) {
            throw new InvalidArgumentException('認証方針IDが不正です。');
        }

        if ($idleTimeoutMinutes < 5 || $idleTimeoutMinutes > 120) {
            throw new InvalidArgumentException('無操作期限は5分以上120分以下で指定してください。');
        }

        if ($absoluteTimeoutHours < 1 || $absoluteTimeoutHours > 24) {
            throw new InvalidArgumentException('絶対期限は1時間以上24時間以下で指定してください。');
        }

        if ($absoluteTimeoutHours * 60 < $idleTimeoutMinutes) {
            throw new InvalidArgumentException('絶対期限は無操作期限以上で指定してください。');
        }

        if (
            $reauthenticationMinutes < 1
            || $failureWindowMinutes < 1
            || $failureThreshold < 1
            || $maximumDelayMinutes < 1
        ) {
            throw new InvalidArgumentException('認証方針の回数と期間は1以上で指定してください。');
        }

        if ($lockVersion < 0) {
            throw new InvalidArgumentException('ロック版は0以上で指定してください。');
        }
    }
}
