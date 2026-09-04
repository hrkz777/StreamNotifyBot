<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAdministratorRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private AdministratorRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        $this->repository = new DoctrineAdministratorRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itStoresAndLoadsAPendingAdministrator(): void
    {
        $administrator = $this->pendingAdministrator();

        $this->repository->add($administrator);

        $storedAdministrator = $this->repository->findById($administrator->id);
        self::assertNotNull($storedAdministrator);
        self::assertSame($administrator->id, $storedAdministrator->id);
        self::assertSame('system.owner', $storedAdministrator->loginId);
        self::assertSame('管理者', $storedAdministrator->displayName);
        self::assertSame(AdministratorRole::Owner, $storedAdministrator->role);
        self::assertSame(AdministratorStatus::Pending, $storedAdministrator->status);
        self::assertSame('2026-09-04 00:00:00.123456', $storedAdministrator->createdAt->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function itFindsAnAdministratorByANormalizedLoginId(): void
    {
        $administrator = $this->pendingAdministrator();
        $this->repository->add($administrator);

        $storedAdministrator = $this->repository->findByLoginId('  SYSTEM.OWNER  ');

        self::assertNotNull($storedAdministrator);
        self::assertSame($administrator->id, $storedAdministrator->id);
    }

    #[Test]
    public function itReturnsNullWhenTheAdministratorDoesNotExist(): void
    {
        self::assertNull(
            $this->repository->findById('01990d4a-0000-7000-8000-000000000199'),
        );
        self::assertNull($this->repository->findByLoginId('missing.owner'));
    }

    private function pendingAdministrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-04 00:00:00.123456', new DateTimeZone('UTC'));

        return new Administrator(
            id: '01990d4a-0000-7000-8000-000000000120',
            loginId: 'System.Owner',
            displayName: ' 管理者 ',
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Pending,
            passwordHash: null,
            authenticationVersion: 1,
            passwordChangedAt: null,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $now,
            updatedAt: $now,
            lockVersion: 0,
        );
    }
}
