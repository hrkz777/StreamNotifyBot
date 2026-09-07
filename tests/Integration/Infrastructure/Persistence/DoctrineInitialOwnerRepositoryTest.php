<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorAlreadyExists;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use App\Domain\Security\EncryptedSecret;
use App\Infrastructure\Persistence\DoctrineInitialOwnerRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineInitialOwnerRepositoryTest extends KernelTestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000150';

    private Connection $connection;
    private DoctrineInitialOwnerRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'UPDATE authentication_policies SET updated_at = ?',
            ['2026-09-05 00:00:00.000000'],
            [ParameterType::STRING],
        );
        $this->repository = new DoctrineInitialOwnerRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheOnlyActiveOwnerAndCompletesInitialSetupAtomically(): void
    {
        $completedAt = new DateTimeImmutable('2026-09-06 00:01:00.123456+00:00');

        $this->repository->create($this->owner(), $this->credential(), $this->recoveryCodes(), $completedAt);

        $owner = $this->connection->fetchAssociative(
            'SELECT role, status, password_changed_at, totp_enrolled_at, lock_version FROM administrators WHERE id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($owner);
        self::assertSame('owner', $owner['role']);
        self::assertSame('active', $owner['status']);
        self::assertSame('2026-09-06 00:00:00.000000', $owner['password_changed_at']);
        self::assertSame('2026-09-06 00:01:00.123456', $owner['totp_enrolled_at']);
        self::assertSame(1, self::readInteger($owner['lock_version'] ?? null));
        self::assertSame(1, $this->countByAdministrator('administrator_totp_credentials'));
        self::assertSame(10, $this->countByAdministrator('administrator_recovery_codes'));

        $policy = $this->connection->fetchAssociative(
            'SELECT initial_setup_completed_at, updated_at, lock_version FROM authentication_policies',
        );
        self::assertIsArray($policy);
        self::assertSame('2026-09-06 00:01:00.123456', $policy['initial_setup_completed_at']);
        self::assertSame('2026-09-06 00:01:00.123456', $policy['updated_at']);
        self::assertSame(1, self::readInteger($policy['lock_version'] ?? null));
    }

    #[Test]
    public function itConsumesAnInitialSetupTokenWithTheInitialOwnerAtomically(): void
    {
        $tokenHash = hash('sha256', 'initial-setup-token');
        $this->insertInitialSetupToken($tokenHash);
        $completedAt = new DateTimeImmutable('2026-09-06 00:01:00.123456+00:00');

        self::assertTrue($this->repository->createUsingInitialSetupToken(
            $tokenHash,
            $this->owner(),
            $this->credential(),
            $this->recoveryCodes(),
            $completedAt,
        ));

        self::assertSame(1, $this->countRows('administrators'));
        self::assertSame(
            '2026-09-06 00:01:00.123456',
            $this->connection->fetchOne('SELECT consumed_at FROM administrator_tokens'),
        );
        self::assertSame(
            '2026-09-06 00:01:00.123456',
            $this->connection->fetchOne('SELECT initial_setup_completed_at FROM authentication_policies'),
        );
    }

    #[Test]
    public function itDoesNotCreateAnOwnerWhenTheInitialSetupTokenIsUnavailable(): void
    {
        self::assertFalse($this->repository->createUsingInitialSetupToken(
            hash('sha256', 'unknown-token'),
            $this->owner(),
            $this->credential(),
            $this->recoveryCodes(),
            new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
        ));

        self::assertSame(0, $this->countRows('administrators'));
        self::assertSame(0, $this->countRows('administrator_totp_credentials'));
        self::assertSame(0, $this->countRows('administrator_recovery_codes'));
        self::assertNull($this->connection->fetchOne('SELECT initial_setup_completed_at FROM authentication_policies'));
    }

    #[Test]
    public function itRollsBackEveryInsertWhenActivationFails(): void
    {
        try {
            $this->repository->create(
                $this->owner(),
                $this->credential(),
                $this->recoveryCodes(),
                new DateTimeImmutable('+10000-01-01T00:00:00+00:00'),
            );
            self::fail('MariaDBの範囲外日時による有効化失敗が発生しませんでした。');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame(0, $this->countRows('administrators'));
        self::assertSame(0, $this->countRows('administrator_totp_credentials'));
        self::assertSame(0, $this->countRows('administrator_recovery_codes'));
        self::assertNull($this->connection->fetchOne('SELECT initial_setup_completed_at FROM authentication_policies'));
    }

    #[Test]
    public function itRejectsASecondInitialOwnerWithoutChangingTheCompletedAggregate(): void
    {
        $completedAt = new DateTimeImmutable('2026-09-06 00:01:00+00:00');
        $this->repository->create($this->owner(), $this->credential(), $this->recoveryCodes(), $completedAt);

        try {
            $this->repository->create($this->owner(), $this->credential(), $this->recoveryCodes(), $completedAt);
            self::fail('2人目の初期ownerが拒否されませんでした。');
        } catch (InitialSetupAlreadyCompleted $exception) {
            self::assertSame('初期ownerは既に作成されています。', $exception->getMessage());
        }

        self::assertSame(1, $this->countRows('administrators'));
        self::assertSame(1, $this->countByAdministrator('administrator_totp_credentials'));
        self::assertSame(10, $this->countByAdministrator('administrator_recovery_codes'));
    }

    #[Test]
    public function itRejectsAnInvalidRecoveryCodeCountBeforeChangingTheDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('初期ownerには10件の回復コードが必要です。');

        $this->repository->create(
            $this->owner(),
            $this->credential(),
            array_slice($this->recoveryCodes(), 0, 9),
            new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
        );
    }

    #[Test]
    public function itRejectsInitialSetupWhenAnAdministratorAlreadyExists(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->owner());

        try {
            $this->repository->create(
                $this->owner(),
                $this->credential(),
                $this->recoveryCodes(),
                new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
            );
            self::fail('既存管理者がある状態での初期設定が拒否されませんでした。');
        } catch (AdministratorAlreadyExists $exception) {
            self::assertSame('既存の管理者があるため初期ownerを作成できません。', $exception->getMessage());
        }

        self::assertSame(1, $this->countRows('administrators'));
        self::assertSame(0, $this->countByAdministrator('administrator_totp_credentials'));
        self::assertSame(0, $this->countByAdministrator('administrator_recovery_codes'));
        self::assertNull($this->connection->fetchOne('SELECT initial_setup_completed_at FROM authentication_policies'));
    }

    private function owner(): Administrator
    {
        $createdAt = new DateTimeImmutable('2026-09-06 00:00:00+00:00');

        return new Administrator(
            id: self::OWNER_ID,
            loginId: 'system.owner',
            displayName: '管理者',
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Pending,
            passwordHash: password_hash('integration-test-password', PASSWORD_ARGON2ID),
            authenticationVersion: 1,
            passwordChangedAt: $createdAt,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            lockVersion: 0,
        );
    }

    private function credential(): AdministratorTotpCredential
    {
        return new AdministratorTotpCredential(
            self::OWNER_ID,
            new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'),
            59_608_320,
        );
    }

    /** @return list<AdministratorRecoveryCode> */
    private function recoveryCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = new AdministratorRecoveryCode(
                sprintf('01990d4a-0000-7000-8000-%012d', 160 + $index),
                self::OWNER_ID,
                hash('sha256', sprintf('recovery-code-%d', $index)),
                new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
                null,
            );
        }

        return $codes;
    }

    private function ownerIdBinary(): string
    {
        return Uuid::fromString(self::OWNER_ID)->toBinary();
    }

    private function insertInitialSetupToken(string $tokenHash): void
    {
        $binaryHash = hex2bin($tokenHash);
        self::assertIsString($binaryHash);
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_tokens (
                    id, administrator_id, purpose, token_hash, created_by_administrator_id,
                    authentication_version, created_at, expires_at, consumed_at, revoked_at
                ) VALUES (?, NULL, ?, ?, NULL, NULL, ?, ?, NULL, NULL)
                SQL,
            [
                Uuid::fromString('01990d4a-0000-7000-8000-000000000171')->toBinary(),
                AdministratorTokenPurpose::InitialSetup->value,
                $binaryHash,
                '2026-09-05 00:00:00.000000',
                '2026-09-07 00:00:00.000000',
            ],
            [
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    private function countByAdministrator(string $table): int
    {
        $sql = match ($table) {
            'administrator_totp_credentials' => 'SELECT COUNT(*) FROM administrator_totp_credentials WHERE administrator_id = ?',
            'administrator_recovery_codes' => 'SELECT COUNT(*) FROM administrator_recovery_codes WHERE administrator_id = ?',
            default => self::fail('未対応のテーブルです。'),
        };
        $count = $this->connection->fetchOne($sql, [$this->ownerIdBinary()], [ParameterType::BINARY]);

        return self::readInteger($count);
    }

    private function countRows(string $table): int
    {
        $sql = match ($table) {
            'administrators' => 'SELECT COUNT(*) FROM administrators',
            'administrator_totp_credentials' => 'SELECT COUNT(*) FROM administrator_totp_credentials',
            'administrator_recovery_codes' => 'SELECT COUNT(*) FROM administrator_recovery_codes',
            default => self::fail('未対応のテーブルです。'),
        };

        return self::readInteger($this->connection->fetchOne($sql));
    }

    private static function readInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int) $value;
        }

        self::fail('DBから整数として解釈できない値が返されました。');
    }
}
