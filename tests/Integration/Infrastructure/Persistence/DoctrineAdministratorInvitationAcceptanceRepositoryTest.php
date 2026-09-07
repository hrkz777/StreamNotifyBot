<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\EncryptedSecret;
use App\Infrastructure\Persistence\DoctrineAdministratorInvitationAcceptanceRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorInvitationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorInvitationAcceptanceRepositoryTest extends KernelTestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000190';
    private const string TOKEN = 'test-invitation-token';

    private Connection $connection;
    private DoctrineAdministratorInvitationAcceptanceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAdministratorInvitationAcceptanceRepository($this->connection);
        $this->createInvitation();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itActivatesTheInvitedAdministratorAndConsumesTheInvitationAtomically(): void
    {
        $acceptedAt = new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00');

        self::assertSame(self::ADMINISTRATOR_ID, $this->repository->findTargetAdministratorId(hash('sha256', self::TOKEN), $acceptedAt));
        self::assertTrue($this->repository->accept(
            hash('sha256', self::TOKEN),
            self::ADMINISTRATOR_ID,
            password_hash('correct horse battery staple', PASSWORD_ARGON2ID),
            $this->credential(),
            $this->recoveryCodes($acceptedAt),
            $acceptedAt,
        ));

        $administrator = $this->connection->fetchAssociative(
            'SELECT status, password_changed_at, totp_enrolled_at, updated_at, lock_version FROM administrators WHERE id = ?',
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($administrator);
        self::assertSame('active', $administrator['status']);
        self::assertSame('2026-09-08 00:01:00.123456', $administrator['password_changed_at']);
        self::assertSame('2026-09-08 00:01:00.123456', $administrator['totp_enrolled_at']);
        self::assertSame('2026-09-08 00:01:00.123456', $administrator['updated_at']);
        self::assertSame(1, self::integer($administrator['lock_version']));
        self::assertSame(1, $this->countRows('administrator_totp_credentials'));
        self::assertSame(10, $this->countRows('administrator_recovery_codes'));
        self::assertSame(1, $this->countRows('administrator_tokens', 'consumed_at IS NOT NULL'));
    }

    #[Test]
    public function itRejectsAConsumedInvitationWithoutReplacingCredentials(): void
    {
        $acceptedAt = new DateTimeImmutable('2026-09-08 00:01:00+00:00');
        $arguments = [
            hash('sha256', self::TOKEN), self::ADMINISTRATOR_ID,
            password_hash('correct horse battery staple', PASSWORD_ARGON2ID),
            $this->credential(), $this->recoveryCodes($acceptedAt), $acceptedAt,
        ];
        self::assertTrue($this->repository->accept(...$arguments));

        self::assertFalse($this->repository->accept(...$arguments));
        self::assertSame(1, $this->countRows('administrator_totp_credentials'));
        self::assertSame(10, $this->countRows('administrator_recovery_codes'));
    }

    #[Test]
    public function itRollsBackWhenRecoveryCodeInsertionFails(): void
    {
        $acceptedAt = new DateTimeImmutable('2026-09-08 00:01:00+00:00');
        $this->connection->executeStatement(
            'INSERT INTO administrator_recovery_codes (id, administrator_id, code_hash, created_at, used_at) VALUES (?, ?, ?, ?, NULL)',
            [
                Uuid::fromString('01990d4a-0000-7000-8000-000000000300')->toBinary(),
                $this->administratorIdBinary(),
                hex2bin(hash('sha256', 'recovery-code-0')),
                '2026-09-08 00:00:30.000000',
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );

        $this->expectException(UniqueConstraintViolationException::class);
        try {
            $this->repository->accept(
                hash('sha256', self::TOKEN),
                self::ADMINISTRATOR_ID,
                password_hash('correct horse battery staple', PASSWORD_ARGON2ID),
                $this->credential(),
                $this->recoveryCodes($acceptedAt),
                $acceptedAt,
            );
        } finally {
            $administrator = $this->connection->fetchAssociative(
                'SELECT status, password_hash, totp_enrolled_at, lock_version FROM administrators WHERE id = ?',
                [$this->administratorIdBinary()],
                [ParameterType::BINARY],
            );
            self::assertIsArray($administrator);
            self::assertSame('pending', $administrator['status']);
            self::assertNull($administrator['password_hash']);
            self::assertNull($administrator['totp_enrolled_at']);
            self::assertSame(0, self::integer($administrator['lock_version']));
            self::assertSame(0, $this->countRows('administrator_totp_credentials'));
            self::assertSame(1, $this->countRows('administrator_recovery_codes'));
            self::assertSame(0, $this->countRows('administrator_tokens', 'consumed_at IS NOT NULL'));
        }
    }

    private function createInvitation(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $administrator = new Administrator(
            self::ADMINISTRATOR_ID,
            'invited.admin',
            '招待管理者',
            AdministratorRole::Administrator,
            AdministratorStatus::Pending,
            null,
            1,
            null,
            null,
            null,
            null,
            null,
            $createdAt,
            $createdAt,
            0,
        );
        $token = new AdministratorToken(
            '01990d4a-0000-7000-8000-000000000191',
            self::ADMINISTRATOR_ID,
            AdministratorTokenPurpose::Invitation,
            hash('sha256', self::TOKEN),
            null,
            1,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            null,
            null,
        );

        (new DoctrineAdministratorInvitationRepository($this->connection))->create($administrator, $token);
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
    private function recoveryCodes(DateTimeImmutable $createdAt, bool $duplicateHash = false): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $hashIndex = $duplicateHash && $index === 1 ? 0 : $index;
            $codes[] = new AdministratorRecoveryCode(
                sprintf('01990d4a-0000-7000-8000-%012d', 192 + $index),
                self::ADMINISTRATOR_ID,
                hash('sha256', sprintf('recovery-code-%d', $hashIndex)),
                $createdAt,
                null,
            );
        }

        return $codes;
    }

    private function administratorIdBinary(): string
    {
        return Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary();
    }

    private function countRows(string $table, string $condition = '1 = 1'): int
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE administrator_id = ? AND %s', $table, $condition),
            [$this->administratorIdBinary()],
            [ParameterType::BINARY],
        );

        return self::integer($value);
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
