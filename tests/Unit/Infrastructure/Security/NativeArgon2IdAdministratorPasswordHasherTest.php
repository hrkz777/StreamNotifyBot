<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\NativeArgon2IdAdministratorPasswordHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeArgon2IdAdministratorPasswordHasherTest extends TestCase
{
    #[Test]
    public function itHashesAndVerifiesWithTheRequiredArgon2IdParameters(): void
    {
        $hasher = new NativeArgon2IdAdministratorPasswordHasher();
        $passwordHash = $hasher->hash('a unique passphrase!');
        $hashInformation = password_get_info($passwordHash);
        $options = $hashInformation['options'];
        self::assertIsArray($options);

        self::assertStringStartsWith('$argon2id$', $passwordHash);
        self::assertSame(PASSWORD_ARGON2ID, $hashInformation['algo']);
        self::assertSame(NativeArgon2IdAdministratorPasswordHasher::MEMORY_COST_KIB, $options['memory_cost'] ?? null);
        self::assertSame(NativeArgon2IdAdministratorPasswordHasher::TIME_COST, $options['time_cost'] ?? null);
        self::assertSame(NativeArgon2IdAdministratorPasswordHasher::THREADS, $options['threads'] ?? null);
        self::assertTrue($hasher->verify($passwordHash, 'a unique passphrase!'));
        self::assertFalse($hasher->verify($passwordHash, 'a different passphrase!'));
        self::assertFalse($hasher->needsRehash($passwordHash));
    }

    #[Test]
    public function itRejectsNonArgon2IdHashesWithoutFallingBack(): void
    {
        $hasher = new NativeArgon2IdAdministratorPasswordHasher();
        $bcryptHash = password_hash('a unique passphrase!', PASSWORD_BCRYPT);

        self::assertFalse($hasher->verify($bcryptHash, 'a unique passphrase!'));
        self::assertTrue($hasher->needsRehash($bcryptHash));
    }

    #[Test]
    public function itDetectsArgon2IdHashesUsingDifferentParameters(): void
    {
        $hasher = new NativeArgon2IdAdministratorPasswordHasher();
        $passwordHash = password_hash('a unique passphrase!', PASSWORD_ARGON2ID, [
            'memory_cost' => NativeArgon2IdAdministratorPasswordHasher::MEMORY_COST_KIB,
            'time_cost' => NativeArgon2IdAdministratorPasswordHasher::TIME_COST + 1,
            'threads' => NativeArgon2IdAdministratorPasswordHasher::THREADS,
        ]);

        self::assertTrue($hasher->verify($passwordHash, 'a unique passphrase!'));
        self::assertTrue($hasher->needsRehash($passwordHash));
    }
}
