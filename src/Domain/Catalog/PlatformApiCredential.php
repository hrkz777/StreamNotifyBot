<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Security\EncryptedSecret;
use InvalidArgumentException;

final readonly class PlatformApiCredential
{
    public function __construct(
        public string $id,
        public Platform $platform,
        public EncryptedSecret $encryptedValue,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('プラットフォームAPI接続情報IDは小文字標準形式のUUIDv7で指定してください。');
        }
    }
}
