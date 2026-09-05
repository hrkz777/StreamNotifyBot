<?php

declare(strict_types=1);

namespace App\Domain\Security;

interface EncryptionKeyRing
{
    public function current(): EncryptionKey;

    public function find(string $keyId): ?EncryptionKey;
}
