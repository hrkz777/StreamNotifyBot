<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;
use LogicException;

final readonly class EncryptionKey
{
    public const int BYTE_LENGTH = 32;

    public function __construct(
        public string $id,
        #[\SensitiveParameter] private string $value,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('暗号化鍵IDの形式が不正です。');
        }

        if (strlen($value) !== self::BYTE_LENGTH) {
            throw new InvalidArgumentException('暗号化鍵は32バイトで指定してください。');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /** @return array{id: string, value: string} */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'value' => '[redacted]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('暗号化鍵はシリアライズできません。');
    }
}
