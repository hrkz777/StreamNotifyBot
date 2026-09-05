<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorPasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plainPassword): string;

    public function verify(string $passwordHash, #[\SensitiveParameter] string $plainPassword): bool;

    public function needsRehash(string $passwordHash): bool;
}
