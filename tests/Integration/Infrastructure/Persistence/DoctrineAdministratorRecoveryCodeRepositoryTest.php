<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Persistence\DoctrineAdministratorRecoveryCodeRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorRecoveryCodeRepositoryTest extends KernelTestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string FIRST_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string SECOND_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Connection $connection;
    private DoctrineAdministratorRecoveryCodeRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAdministratorRecoveryCodeRepository($this->connection);

        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator());
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itReplacesCodesAndStoresOnlyBinaryHashes(): void
    {
        $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
            $this->code('01990d4a-0000-7000-8000-000000000160', self::FIRST_HASH),
            $this->code('01990d4a-0000-7000-8000-000000000161', self::SECOND_HASH),
        ]);

        $storedHashes = $this->connection->fetchFirstColumn(
            'SELECT code_hash FROM administrator_recovery_codes WHERE administrator_id = ? ORDER BY id',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertCount(2, $storedHashes);
        $hexHashes = [];
        foreach ($storedHashes as $storedHash) {
            self::assertIsString($storedHash);
            $hexHashes[] = bin2hex($storedHash);
        }
        self::assertSame([self::FIRST_HASH, self::SECOND_HASH], $hexHashes);

        $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
            $this->code('01990d4a-0000-7000-8000-000000000162', self::SECOND_HASH),
        ]);

        $storedCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM administrator_recovery_codes WHERE administrator_id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsNumeric($storedCount);
        self::assertSame(1, (int) $storedCount);
    }

    #[Test]
    public function itConsumesARecoveryCodeOnlyOnce(): void
    {
        $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
            $this->code('01990d4a-0000-7000-8000-000000000160', self::FIRST_HASH),
        ]);
        $usedAt = new DateTimeImmutable('2026-09-06 00:01:00.123456', new DateTimeZone('UTC'));

        self::assertTrue($this->repository->consumeByHash(self::ADMINISTRATOR_ID, self::FIRST_HASH, $usedAt));
        self::assertFalse($this->repository->consumeByHash(self::ADMINISTRATOR_ID, self::FIRST_HASH, $usedAt));
        $storedUsedAt = $this->connection->fetchOne(
            'SELECT used_at FROM administrator_recovery_codes WHERE administrator_id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsString($storedUsedAt);
        self::assertSame('2026-09-06 00:01:00.123456', $storedUsedAt);
    }

    #[Test]
    public function itDoesNotConsumeACodeBeforeItsCreationTime(): void
    {
        $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
            $this->code('01990d4a-0000-7000-8000-000000000160', self::FIRST_HASH),
        ]);

        self::assertFalse($this->repository->consumeByHash(
            self::ADMINISTRATOR_ID,
            self::FIRST_HASH,
            new DateTimeImmutable('2026-09-05 23:59:59+00:00'),
        ));
    }

    #[Test]
    public function itRejectsCodesForAnotherAdministratorBeforeDeletingExistingCodes(): void
    {
        $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
            $this->code('01990d4a-0000-7000-8000-000000000160', self::FIRST_HASH),
        ]);

        try {
            $this->repository->replaceForAdministrator(self::ADMINISTRATOR_ID, [
                new AdministratorRecoveryCode(
                    '01990d4a-0000-7000-8000-000000000161',
                    '01990d4a-0000-7000-8000-000000000151',
                    self::SECOND_HASH,
                    new DateTimeImmutable('2026-09-06 00:00:00+00:00'),
                    null,
                ),
            ]);
            self::fail('異なる管理者の回復コードが拒否されませんでした。');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('異なる管理者の回復コードは一括保存できません。', $exception->getMessage());
        }

        $storedCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM administrator_recovery_codes WHERE administrator_id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsNumeric($storedCount);
        self::assertSame(1, (int) $storedCount);
    }

    private function code(string $id, string $hash): AdministratorRecoveryCode
    {
        return new AdministratorRecoveryCode(
            $id,
            self::ADMINISTRATOR_ID,
            $hash,
            new DateTimeImmutable('2026-09-06 00:00:00+00:00'),
            null,
        );
    }

    private function administratorIdBinary(): string
    {
        return Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary();
    }

    private function administrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-06 00:00:00+00:00');

        return new Administrator(
            id: self::ADMINISTRATOR_ID,
            loginId: 'system.owner',
            displayName: '管理者',
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
