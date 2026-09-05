<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\AdministratorPasswordHasher;
use LogicException;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final readonly class NativeArgon2IdAdministratorPasswordHasher implements AdministratorPasswordHasher, PasswordHasherInterface
{
    public const int MEMORY_COST_KIB = 19 * 1024;
    public const int TIME_COST = 2;
    public const int THREADS = 1;

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private array $options;

    public function __construct()
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new LogicException('この実行環境ではArgon2idを利用できません。');
        }

        $this->options = [
            'memory_cost' => self::MEMORY_COST_KIB,
            'time_cost' => self::TIME_COST,
            'threads' => self::THREADS,
        ];
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $passwordHash, #[\SensitiveParameter] string $plainPassword): bool
    {
        return str_starts_with($passwordHash, '$argon2id$')
            && password_verify($plainPassword, $passwordHash);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return !str_starts_with($passwordHash, '$argon2id$')
            || password_needs_rehash($passwordHash, PASSWORD_ARGON2ID, $this->options);
    }
}
