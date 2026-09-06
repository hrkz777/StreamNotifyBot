<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\EncryptedSecret;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorTotpEnrollmentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorTotpEnrollmentRepositoryTest extends KernelTestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000150';

    private Connection $connection;
    private DoctrineAdministratorTotpEnrollmentRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAdministratorTotpEnrollmentRepository($this->connection);

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
    public function itAtomicallyConfirmsTotpAndPersistsTenRecoveryCodeHashes(): void
    {
        $enrolledAt = new DateTimeImmutable('2026-09-06 00:01:00.123456+00:00');

        self::assertTrue($this->repository->confirm($this->credential(), $this->recoveryCodes(), $enrolledAt));

        $administrator = $this->connection->fetchAssociative(
            'SELECT status, totp_enrolled_at, updated_at, lock_version FROM administrators WHERE id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($administrator);
        self::assertSame('pending', $administrator['status']);
        self::assertSame('2026-09-06 00:01:00.123456', $administrator['totp_enrolled_at']);
        self::assertSame('2026-09-06 00:01:00.123456', $administrator['updated_at']);
        self::assertSame(1, self::readInteger($administrator['lock_version'] ?? null));

        $storedTimeStep = $this->connection->fetchOne(
            'SELECT last_accepted_time_step FROM administrator_totp_credentials WHERE administrator_id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsNumeric($storedTimeStep);
        self::assertSame(59_608_320, (int) $storedTimeStep);

        $storedHashes = $this->connection->fetchFirstColumn(
            'SELECT code_hash FROM administrator_recovery_codes WHERE administrator_id = ? ORDER BY id',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertCount(10, $storedHashes);
        foreach ($storedHashes as $index => $storedHash) {
            self::assertIsString($storedHash);
            self::assertSame(hash('sha256', sprintf('recovery-code-%d', $index)), bin2hex($storedHash));
        }
    }

    #[Test]
    public function itRejectsASecondConfirmationWithoutReplacingCredentialsOrCodes(): void
    {
        $enrolledAt = new DateTimeImmutable('2026-09-06 00:01:00+00:00');
        self::assertTrue($this->repository->confirm($this->credential(), $this->recoveryCodes(), $enrolledAt));

        self::assertFalse($this->repository->confirm(
            $this->credential(),
            $this->recoveryCodes(),
            new DateTimeImmutable('2026-09-06 00:02:00+00:00'),
        ));

        self::assertSame(1, $this->countRows('administrator_totp_credentials'));
        self::assertSame(10, $this->countRows('administrator_recovery_codes'));
    }

    #[Test]
    public function itRejectsANonPendingAdministratorWithoutWritingCredentials(): void
    {
        $this->connection->executeStatement(
            "UPDATE administrators SET status = 'disabled', disabled_at = ? WHERE id = ?",
            ['2026-09-06 00:00:30.000000', $this->administratorIdBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        self::assertFalse($this->repository->confirm(
            $this->credential(),
            $this->recoveryCodes(),
            new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
        ));
        self::assertSame(0, $this->countRows('administrator_totp_credentials'));
        self::assertSame(0, $this->countRows('administrator_recovery_codes'));
    }

    #[Test]
    public function itRollsBackTheAdministratorAndCredentialWhenRecoveryCodeInsertionFails(): void
    {
        try {
            $this->repository->confirm(
                $this->credential(),
                $this->recoveryCodes(duplicateHash: true),
                new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
            );
            self::fail('重複する回復コードハッシュが拒否されませんでした。');
        } catch (UniqueConstraintViolationException) {
        }

        $administrator = $this->connection->fetchAssociative(
            'SELECT totp_enrolled_at, lock_version FROM administrators WHERE id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($administrator);
        self::assertNull($administrator['totp_enrolled_at']);
        self::assertSame(0, self::readInteger($administrator['lock_version'] ?? null));
        self::assertSame(0, $this->countRows('administrator_totp_credentials'));
        self::assertSame(0, $this->countRows('administrator_recovery_codes'));
    }

    private function credential(): AdministratorTotpCredential
    {
        return new AdministratorTotpCredential(
            self::ADMINISTRATOR_ID,
            new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'),
            59_608_320,
        );
    }

    /** @return list<AdministratorRecoveryCode> */
    private function recoveryCodes(bool $duplicateHash = false): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $hashIndex = $duplicateHash && $index === 1 ? 0 : $index;
            $codes[] = new AdministratorRecoveryCode(
                sprintf('01990d4a-0000-7000-8000-%012d', 160 + $index),
                self::ADMINISTRATOR_ID,
                hash('sha256', sprintf('recovery-code-%d', $hashIndex)),
                new DateTimeImmutable('2026-09-06 00:01:00+00:00'),
                null,
            );
        }

        return $codes;
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

    private function administratorIdBinary(): string
    {
        return Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary();
    }

    private function countRows(string $table): int
    {
        $sql = match ($table) {
            'administrator_totp_credentials' => 'SELECT COUNT(*) FROM administrator_totp_credentials WHERE administrator_id = ?',
            'administrator_recovery_codes' => 'SELECT COUNT(*) FROM administrator_recovery_codes WHERE administrator_id = ?',
            default => self::fail('未対応のテーブルです。'),
        };
        $count = $this->connection->fetchOne(
            $sql,
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );

        return self::readInteger($count);
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
