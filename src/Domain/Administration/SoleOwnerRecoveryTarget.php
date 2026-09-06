<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use InvalidArgumentException;

final readonly class SoleOwnerRecoveryTarget
{
    public function __construct(
        public string $administratorId,
        public string $loginId,
        public string $displayName,
        public int $lockVersion,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $administratorId) !== 1) {
            throw new InvalidArgumentException('回復対象の管理者IDが不正です。');
        }

        if ($loginId === '' || $displayName === '' || $lockVersion < 0) {
            throw new InvalidArgumentException('回復対象の管理者情報が不正です。');
        }
    }
}
