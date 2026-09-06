<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

final readonly class EncryptedSecret
{
    public const int FORMAT_VERSION = 1;
    public const int NONCE_BYTE_LENGTH = 24;
    public const int AUTHENTICATION_TAG_BYTE_LENGTH = 16;

    public function __construct(
        public string $encryptedValue,
        public string $nonce,
        public string $keyId,
        public int $formatVersion = self::FORMAT_VERSION,
    ) {
        if (strlen($encryptedValue) < self::AUTHENTICATION_TAG_BYTE_LENGTH) {
            throw new InvalidArgumentException('暗号文の形式が不正です。');
        }

        if (strlen($nonce) !== self::NONCE_BYTE_LENGTH) {
            throw new InvalidArgumentException('暗号化ノンスは24バイトで指定してください。');
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('暗号化鍵IDの形式が不正です。');
        }

        if ($formatVersion !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('未対応の秘密情報暗号化形式です。');
        }
    }
}
