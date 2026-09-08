<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Persistence\DoctrineAdministratorDeactivationRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorDeactivationRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAdministratorRepository $administrators;
    private DoctrineAdministratorDeactivationRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->administrators = new DoctrineAdministratorRepository($connection);
        $this->repository = new DoctrineAdministratorDeactivationRepository($connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itDoesNotDeactivateTheLastActiveOwner(): void
    {
        $owner = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000500', 'sole.owner', AdministratorRole::Owner);
        $this->administrators->add($owner);

        self::assertFalse($this->repository->deactivate($owner->id, new DateTimeImmutable('2026-09-08 00:01:00+00:00')));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM administrators WHERE id = ?', [$this->binaryId($owner->id)], [ParameterType::BINARY]));
    }

    #[Test]
    public function itDeactivatesAnOwnerWhenAnotherActiveOwnerRemains(): void
    {
        $first = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000501', 'first.owner', AdministratorRole::Owner);
        $second = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000502', 'second.owner', AdministratorRole::Owner);
        $this->administrators->add($first);
        $this->administrators->add($second);

        self::assertTrue($this->repository->deactivate($first->id, new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00')));

        $row = $this->connection->fetchAssociative('SELECT status, disabled_at, authentication_version, lock_version FROM administrators WHERE id = ?', [$this->binaryId($first->id)], [ParameterType::BINARY]);
        self::assertIsArray($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame('2026-09-08 00:01:00.123456', $row['disabled_at']);
        self::assertSame(2, self::integer($row['authentication_version']));
        self::assertSame(1, self::integer($row['lock_version']));
    }

    private function activeAdministrator(string $id, string $loginId, AdministratorRole $role): Administrator
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $passwordHash = password_hash('test-only-password', PASSWORD_ARGON2ID);

        return new Administrator($id, $loginId, '管理者', $role, AdministratorStatus::Active, $passwordHash, 1, $now, $now, null, null, null, $now, $now, 0);
    }

    private function binaryId(string $id): string
    {
        return Uuid::fromString($id)->toBinary();
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        self::fail('DBから整数を取得できませんでした。');
    }
}
