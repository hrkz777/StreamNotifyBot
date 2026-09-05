<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRepository;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Security\AdministratorUserProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdministratorUserProviderTest extends TestCase
{
    #[Test]
    public function itLoadsAnActiveAdministratorByNormalizedLoginId(): void
    {
        $administrator = $this->administrator(AdministratorStatus::Active);
        $repository = $this->createMock(AdministratorRepository::class);
        $repository->expects(self::once())
            ->method('findByLoginId')
            ->with('owner.login')
            ->willReturn($administrator);

        $user = (new AdministratorUserProvider($repository))->loadUserByIdentifier(' OWNER.LOGIN ');

        self::assertSame($administrator->id, $user->getId());
        self::assertSame('owner.login', $user->getUserIdentifier());
    }

    #[Test]
    public function itRejectsAnInactiveAdministratorWithoutDisclosingItsStatus(): void
    {
        $repository = $this->createStub(AdministratorRepository::class);
        $repository->method('findByLoginId')->willReturn($this->administrator(AdministratorStatus::Disabled));

        try {
            (new AdministratorUserProvider($repository))->loadUserByIdentifier('owner.login');
            self::fail('UserNotFoundException was not thrown.');
        } catch (UserNotFoundException $exception) {
            self::assertSame('owner.login', $exception->getUserIdentifier());
            self::assertSame('Username could not be found.', $exception->getMessageKey());
        }
    }

    #[Test]
    public function itRefreshesAnAdministratorByImmutableId(): void
    {
        $administrator = $this->administrator(AdministratorStatus::Active);
        $repository = $this->createMock(AdministratorRepository::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with($administrator->id)
            ->willReturn($administrator);
        $provider = new AdministratorUserProvider($repository);

        $refreshed = $provider->refreshUser(AdministratorSecurityUser::fromAdministrator($administrator));

        self::assertSame($administrator->id, $refreshed->getId());
    }

    #[Test]
    public function itRejectsUnsupportedSecurityUsers(): void
    {
        $repository = $this->createStub(AdministratorRepository::class);
        $provider = new AdministratorUserProvider($repository);

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser(new InMemoryUser('other', null));
    }

    #[Test]
    public function itOnlySupportsTheAdministratorSecurityUser(): void
    {
        $repository = $this->createStub(AdministratorRepository::class);
        $provider = new AdministratorUserProvider($repository);

        self::assertTrue($provider->supportsClass(AdministratorSecurityUser::class));
        self::assertFalse($provider->supportsClass(InMemoryUser::class));
    }

    private function administrator(AdministratorStatus $status): Administrator
    {
        $now = new DateTimeImmutable('2026-09-05 00:00:00 UTC');

        return new Administrator(
            '01991a3c-7800-7000-8000-000000000001',
            'owner.login',
            '管理責任者',
            AdministratorRole::Owner,
            $status,
            '$argon2id$test-password-hash',
            3,
            $now,
            $now,
            null,
            $status === AdministratorStatus::Disabled ? $now : null,
            null,
            $now,
            $now,
            0,
        );
    }
}
