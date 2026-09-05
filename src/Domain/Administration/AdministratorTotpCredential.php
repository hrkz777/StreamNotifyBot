<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Domain\Security\EncryptedSecret;
use InvalidArgumentException;

final readonly class AdministratorTotpCredential
{
    public function __construct(
        public string $administratorId,
        public EncryptedSecret $encryptedSecret,
        public ?int $lastAcceptedTimeStep,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $administratorId) !== 1) {
            throw new InvalidArgumentException('管理者IDは小文字標準形式のUUIDv7で指定してください。');
        }

        if ($lastAcceptedTimeStep !== null && $lastAcceptedTimeStep < 0) {
            throw new InvalidArgumentException('最終受理TOTP時刻ステップは0以上で指定してください。');
        }
    }
}
