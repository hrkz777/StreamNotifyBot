<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\CommonPasswordChecker;
use Normalizer;
use RuntimeException;

final class FileCommonPasswordChecker implements CommonPasswordChecker
{
    /** @var array<non-empty-string, true>|null */
    private ?array $normalizedPasswords = null;

    public function __construct(private readonly string $passwordListPath)
    {
    }

    public function isCommon(string $password): bool
    {
        $normalizedPassword = self::normalize($password);

        return $normalizedPassword !== '' && isset($this->passwords()[$normalizedPassword]);
    }

    /** @return array<non-empty-string, true> */
    private function passwords(): array
    {
        if ($this->normalizedPasswords !== null) {
            return $this->normalizedPasswords;
        }

        if (!is_readable($this->passwordListPath)) {
            throw new RuntimeException('ローカルパスワード拒否リストを読み込めません。');
        }

        $lines = file($this->passwordListPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('ローカルパスワード拒否リストを読み込めません。');
        }

        $passwords = [];
        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '' || str_starts_with($candidate, '#')) {
                continue;
            }

            $normalizedCandidate = self::normalize($candidate);
            if ($normalizedCandidate !== '') {
                $passwords[$normalizedCandidate] = true;
            }
        }

        return $this->normalizedPasswords = $passwords;
    }

    private static function normalize(string $password): string
    {
        $normalizedPassword = Normalizer::normalize($password, Normalizer::FORM_KC);

        return mb_strtolower(is_string($normalizedPassword) ? $normalizedPassword : $password, 'UTF-8');
    }
}
