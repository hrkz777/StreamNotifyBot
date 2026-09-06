<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorRecoveryCodeAlgorithm
{
    /** @return list<string> */
    public function generate(): array;

    public function normalize(#[\SensitiveParameter] string $code): string;

    public function hash(#[\SensitiveParameter] string $code): string;

    public function verify(string $codeHash, #[\SensitiveParameter] string $code): bool;
}
