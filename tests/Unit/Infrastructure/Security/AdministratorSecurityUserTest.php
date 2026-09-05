<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Security\AdministratorSecurityUser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorSecurityUserTest extends TestCase
{
    #[Test]
    public function itMapsAnOwnerWithoutSerializingThePasswordHash(): void
    {
        $user = AdministratorSecurityUser::fromAdministrator($this->administrator(AdministratorRole::Owner));

        self::assertSame('owner.login', $user->getUserIdentifier());
        self::assertSame('管理責任者', $user->getDisplayName());
        self::assertSame(['ROLE_OWNER', 'ROLE_ADMINISTRATOR'], $user->getRoles());
        self::assertSame('$argon2id$test-password-hash', $user->getPassword());

        $serialized = serialize($user);
        self::assertStringNotContainsString('test-password-hash', $serialized);

        $restored = unserialize($serialized);
        self::assertInstanceOf(AdministratorSecurityUser::class, $restored);
        self::assertNull($restored->getPassword());
        self::assertTrue($user->isEqualTo($restored));
    }

    #[Test]
    public function itMapsAnAdministratorWithoutOwnerPrivileges(): void
    {
        $user = AdministratorSecurityUser::fromAdministrator($this->administrator(AdministratorRole::Administrator));

        self::assertSame(['ROLE_ADMINISTRATOR'], $user->getRoles());
    }

    private function administrator(AdministratorRole $role): Administrator
    {
        $now = new DateTimeImmutable('2026-09-05 00:00:00 UTC');

        return new Administrator(
            '01991a3c-7800-7000-8000-000000000001',
            'owner.login',
            '管理責任者',
            $role,
            AdministratorStatus::Active,
            '$argon2id$test-password-hash',
            3,
            $now,
            $now,
            null,
            null,
            null,
            $now,
            $now,
            0,
        );
    }
}
