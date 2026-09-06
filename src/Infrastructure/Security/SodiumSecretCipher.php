<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\EncryptionKeyRing;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretDecryptionFailed;
use App\Domain\Security\SecretPurpose;
use InvalidArgumentException;

final readonly class SodiumSecretCipher implements SecretCipher
{
    private const string ADDITIONAL_DATA_PREFIX = "stream-notify-bot:secret:v1\0";

    public function __construct(private EncryptionKeyRing $keyRing)
    {
    }

    public function encrypt(
        #[\SensitiveParameter] string $plainValue,
        SecretPurpose $purpose,
        string $recordId,
    ): EncryptedSecret {
        self::assertRecordId($recordId);
        $key = $this->keyRing->current();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $encryptedValue = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plainValue,
            self::additionalData($purpose, $recordId, $key->id),
            $nonce,
            $key->value(),
        );

        return new EncryptedSecret($encryptedValue, $nonce, $key->id);
    }

    public function decrypt(
        EncryptedSecret $encryptedSecret,
        SecretPurpose $purpose,
        string $recordId,
    ): string {
        self::assertRecordId($recordId);
        $key = $this->keyRing->find($encryptedSecret->keyId);
        if ($key === null) {
            throw new SecretDecryptionFailed();
        }

        $plainValue = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $encryptedSecret->encryptedValue,
            self::additionalData($purpose, $recordId, $key->id),
            $encryptedSecret->nonce,
            $key->value(),
        );
        if (!is_string($plainValue)) {
            throw new SecretDecryptionFailed();
        }

        return $plainValue;
    }

    private static function additionalData(SecretPurpose $purpose, string $recordId, string $keyId): string
    {
        return self::ADDITIONAL_DATA_PREFIX.$purpose->value."\0{$recordId}\0{$keyId}";
    }

    private static function assertRecordId(string $recordId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $recordId) !== 1) {
            throw new InvalidArgumentException('秘密情報のレコードIDは小文字標準形式のUUIDv7で指定してください。');
        }
    }
}
