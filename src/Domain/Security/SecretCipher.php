<?php

declare(strict_types=1);

namespace App\Domain\Security;

interface SecretCipher
{
    public function encrypt(
        #[\SensitiveParameter] string $plainValue,
        SecretPurpose $purpose,
        string $recordId,
    ): EncryptedSecret;

    public function decrypt(
        EncryptedSecret $encryptedSecret,
        SecretPurpose $purpose,
        string $recordId,
    ): string;
}
