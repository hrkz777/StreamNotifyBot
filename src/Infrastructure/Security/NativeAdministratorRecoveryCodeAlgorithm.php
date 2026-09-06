<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use InvalidArgumentException;

final readonly class NativeAdministratorRecoveryCodeAlgorithm implements AdministratorRecoveryCodeAlgorithm
{
    private const int CODE_COUNT = 10;
    private const int RANDOM_BYTE_LENGTH = 16;
    private const int GROUP_CHARACTER_LENGTH = 8;

    public function generate(): array
    {
        $codes = [];

        while (count($codes) < self::CODE_COUNT) {
            $randomBytes = random_bytes(self::RANDOM_BYTE_LENGTH);

            try {
                $normalizedCode = strtoupper(bin2hex($randomBytes));
            } finally {
                sodium_memzero($randomBytes);
            }

            try {
                $displayCode = implode('-', str_split($normalizedCode, self::GROUP_CHARACTER_LENGTH));
            } finally {
                sodium_memzero($normalizedCode);
            }

            $codes[$displayCode] = true;
        }

        return array_keys($codes);
    }

    public function normalize(#[\SensitiveParameter] string $code): string
    {
        $normalizedCode = strtoupper(str_replace(
            ['-', ' ', "\t", "\r", "\n", "\v", "\f"],
            '',
            $code,
        ));

        if (preg_match('/^[0-9A-F]{32}$/D', $normalizedCode) !== 1) {
            throw new InvalidArgumentException('回復コードの形式が不正です。');
        }

        return $normalizedCode;
    }

    public function hash(#[\SensitiveParameter] string $code): string
    {
        $normalizedCode = $this->normalize($code);

        try {
            return hash('sha256', $normalizedCode);
        } finally {
            sodium_memzero($normalizedCode);
        }
    }

    public function verify(string $codeHash, #[\SensitiveParameter] string $code): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $codeHash) !== 1) {
            return false;
        }

        try {
            $candidateHash = $this->hash($code);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals($codeHash, $candidateHash);
    }
}
