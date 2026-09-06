<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\ConcurrentSoleOwnerRecovery;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\Administration\SoleOwnerRecoveryUnavailable;
use App\Domain\Security\EncryptedSecret;
use App\Infrastructure\Persistence\DoctrineAdministratorRecoveryCodeRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use App\Infrastructure\Persistence\DoctrineInitialOwnerRepository;
use App\Infrastructure\Persistence\DoctrineSoleOwnerCredentialRecoveryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineSoleOwnerCredentialRecoveryRepositoryTest extends KernelTestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000151';

    private Connection $connection;
    private DoctrineSoleOwnerCredentialRecoveryRepository $repository;

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
        $this->createInitialOwner();
        $this->repository = new DoctrineSoleOwnerCredentialRecoveryRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itFindsAndAtomicallyRecoversTheOnlyActiveOwner(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Administrator,
        ));
        $target = $this->repository->findTarget();
        self::assertSame(self::OWNER_ID, $target->administratorId);
        self::assertSame('system.owner', $target->loginId);
        self::assertSame('管理者', $target->displayName);
        self::assertSame(1, $target->lockVersion);
        $this->insertSessionsAndTokens();
        $recoveredAt = new DateTimeImmutable('2026-09-06 00:05:00.123456+00:00');

        $this->repository->recover(
            $target,
            password_hash('new-integration-password', PASSWORD_ARGON2ID),
            $this->newCredential(),
            $this->newRecoveryCodes($recoveredAt),
            $recoveredAt,
        );

        $owner = $this->connection->fetchAssociative(
            'SELECT password_hash, password_changed_at, totp_enrolled_at, authentication_version, updated_at, lock_version FROM administrators WHERE id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($owner);
        self::assertIsString($owner['password_hash']);
        self::assertSame('argon2id', password_get_info($owner['password_hash'])['algoName']);
        self::assertSame('2026-09-06 00:05:00.123456', $owner['password_changed_at']);
        self::assertSame('2026-09-06 00:05:00.123456', $owner['totp_enrolled_at']);
        self::assertSame(2, self::readInteger($owner['authentication_version'] ?? null));
        self::assertSame('2026-09-06 00:05:00.123456', $owner['updated_at']);
        self::assertSame(2, self::readInteger($owner['lock_version'] ?? null));

        $credential = $this->connection->fetchAssociative(
            'SELECT encrypted_value, encryption_nonce, encryption_key_id, last_accepted_time_step FROM administrator_totp_credentials WHERE administrator_id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($credential);
        self::assertSame(str_repeat('x', 16), $credential['encrypted_value']);
        self::assertSame(str_repeat('y', 24), $credential['encryption_nonce']);
        self::assertSame('rotated', $credential['encryption_key_id']);
        self::assertSame(59_611_210, self::readInteger($credential['last_accepted_time_step'] ?? null));

        $storedHashes = $this->connection->fetchFirstColumn(
            'SELECT code_hash FROM administrator_recovery_codes WHERE administrator_id = ? ORDER BY id',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        );
        self::assertCount(10, $storedHashes);
        foreach ($storedHashes as $index => $storedHash) {
            self::assertIsString($storedHash);
            self::assertSame(hash('sha256', sprintf('new-recovery-%d', $index)), bin2hex($storedHash));
        }

        self::assertSame(
            ['2026-09-06 00:05:00.123456', '2026-09-06 00:03:30.000000'],
            $this->connection->fetchFirstColumn('SELECT revoked_at FROM administrator_sessions ORDER BY id'),
        );
        self::assertSame(
            ['2026-09-06 00:05:00.123456', '2026-09-06 00:03:30.000000'],
            $this->connection->fetchFirstColumn('SELECT revoked_at FROM administrator_tokens ORDER BY id'),
        );

        $policy = $this->connection->fetchAssociative(
            'SELECT initial_setup_completed_at, updated_at, lock_version FROM authentication_policies',
        );
        self::assertIsArray($policy);
        self::assertSame('2026-09-06 00:01:00.000000', $policy['initial_setup_completed_at']);
        self::assertSame('2026-09-06 00:01:00.000000', $policy['updated_at']);
        self::assertSame(1, self::readInteger($policy['lock_version'] ?? null));
        self::assertSame(1, self::readInteger($this->connection->fetchOne(
            'SELECT authentication_version FROM administrators WHERE id = ?',
            [Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary()],
            [ParameterType::BINARY],
        )));
    }

    #[Test]
    public function itRejectsRecoveryWhenAnotherNonDeletedOwnerExists(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Owner,
        ));

        $this->expectException(SoleOwnerRecoveryUnavailable::class);

        $this->repository->findTarget();
    }

    #[Test]
    public function itRejectsRecoveryWhenAnotherDisabledOwnerExists(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Owner,
        ));
        $this->connection->executeStatement(
            "UPDATE administrators SET status = 'disabled', disabled_at = ? WHERE id = ?",
            ['2026-09-06 00:03:00.000000', Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        $this->expectException(SoleOwnerRecoveryUnavailable::class);

        $this->repository->findTarget();
    }

    #[Test]
    public function itIgnoresAConsistentDeletedOwnerTombstone(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Owner,
        ));
        $this->connection->executeStatement(
            "UPDATE administrators SET status = 'deleted', password_hash = NULL, deleted_at = ? WHERE id = ?",
            ['2026-09-06 00:03:00.000000', Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        self::assertSame(self::OWNER_ID, $this->repository->findTarget()->administratorId);
    }

    #[Test]
    public function itRejectsAnActiveOwnerWithAnInconsistentDeletionTime(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Owner,
        ));
        $this->connection->executeStatement(
            'UPDATE administrators SET deleted_at = ? WHERE id = ?',
            ['2026-09-06 00:03:00.000000', Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        $this->expectException(SoleOwnerRecoveryUnavailable::class);

        $this->repository->findTarget();
    }

    #[Test]
    public function itRepairsAMissingTotpCredential(): void
    {
        $target = $this->repository->findTarget();
        $recoveredAt = new DateTimeImmutable('2026-09-06 00:05:00+00:00');
        $this->connection->executeStatement(
            'DELETE FROM administrator_totp_credentials WHERE administrator_id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        );

        $this->repository->recover(
            $target,
            password_hash('new-integration-password', PASSWORD_ARGON2ID),
            $this->newCredential(),
            $this->newRecoveryCodes($recoveredAt),
            $recoveredAt,
        );

        self::assertSame(59_611_210, self::readInteger($this->connection->fetchOne(
            'SELECT last_accepted_time_step FROM administrator_totp_credentials WHERE administrator_id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        )));
    }

    #[Test]
    public function itRejectsRecoveryBeforeTheLatestAuthenticationStateWasCreated(): void
    {
        $target = $this->repository->findTarget();
        $this->insertSessionsAndTokens('2026-09-06 00:06:00.000000');
        $recoveredAt = new DateTimeImmutable('2026-09-06 00:05:00+00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('資格情報回復日時より後に作成された認証状態があります。');

        $this->repository->recover(
            $target,
            password_hash('new-integration-password', PASSWORD_ARGON2ID),
            $this->newCredential(),
            $this->newRecoveryCodes($recoveredAt),
            $recoveredAt,
        );
    }

    #[Test]
    public function itRejectsAStaleTargetWithoutChangingCredentials(): void
    {
        $target = $this->repository->findTarget();
        $before = $this->recoveryState();
        $staleTarget = new SoleOwnerRecoveryTarget(
            $target->administratorId,
            $target->loginId,
            $target->displayName,
            0,
        );
        $this->expectException(ConcurrentSoleOwnerRecovery::class);

        try {
            $this->repository->recover(
                $staleTarget,
                password_hash('new-integration-password', PASSWORD_ARGON2ID),
                $this->newCredential(),
                $this->newRecoveryCodes(new DateTimeImmutable('2026-09-06 00:05:00+00:00')),
                new DateTimeImmutable('2026-09-06 00:05:00+00:00'),
            );
        } finally {
            self::assertSame($before, $this->recoveryState());
        }
    }

    #[Test]
    public function itRollsBackAfterDeletingOldCodesWhenANewCodeIdCollides(): void
    {
        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator(
            self::ADMINISTRATOR_ID,
            AdministratorRole::Administrator,
        ));
        $collisionId = '01990d4a-0000-7000-8000-000000000261';
        (new DoctrineAdministratorRecoveryCodeRepository($this->connection))->replaceForAdministrator(
            self::ADMINISTRATOR_ID,
            [new AdministratorRecoveryCode(
                $collisionId,
                self::ADMINISTRATOR_ID,
                hash('sha256', 'other-administrator-code'),
                new DateTimeImmutable('2026-09-06 00:02:00+00:00'),
                null,
            )],
        );
        $this->insertSessionsAndTokens();
        $target = $this->repository->findTarget();
        $before = $this->recoveryState();

        try {
            $this->repository->recover(
                $target,
                password_hash('new-integration-password', PASSWORD_ARGON2ID),
                $this->newCredential(),
                $this->newRecoveryCodes(new DateTimeImmutable('2026-09-06 00:05:00+00:00'), $collisionId),
                new DateTimeImmutable('2026-09-06 00:05:00+00:00'),
            );
            self::fail('他管理者の回復コードIDとの衝突が拒否されませんでした。');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame($before, $this->recoveryState());
    }

    private function createInitialOwner(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-06 00:00:00+00:00');
        $completedAt = new DateTimeImmutable('2026-09-06 00:01:00+00:00');
        (new DoctrineInitialOwnerRepository($this->connection))->create(
            new Administrator(
                self::OWNER_ID,
                'system.owner',
                '管理者',
                AdministratorRole::Owner,
                AdministratorStatus::Pending,
                password_hash('old-integration-password', PASSWORD_ARGON2ID),
                1,
                $createdAt,
                null,
                null,
                null,
                null,
                $createdAt,
                $createdAt,
                0,
            ),
            new AdministratorTotpCredential(
                self::OWNER_ID,
                new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'),
                59_608_320,
            ),
            $this->oldRecoveryCodes($completedAt),
            $completedAt,
        );
    }

    private function administrator(string $id, AdministratorRole $role): Administrator
    {
        $now = new DateTimeImmutable('2026-09-06 00:02:00+00:00');

        return new Administrator(
            $id,
            $role === AdministratorRole::Owner ? 'other.owner' : 'other.administrator',
            '別の管理者',
            $role,
            AdministratorStatus::Active,
            password_hash('other-integration-password', PASSWORD_ARGON2ID),
            1,
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

    private function newCredential(): AdministratorTotpCredential
    {
        return new AdministratorTotpCredential(
            self::OWNER_ID,
            new EncryptedSecret(str_repeat('x', 16), str_repeat('y', 24), 'rotated'),
            59_611_210,
        );
    }

    /** @return list<AdministratorRecoveryCode> */
    private function oldRecoveryCodes(DateTimeImmutable $createdAt): array
    {
        return $this->recoveryCodes(160, 'old-recovery', $createdAt);
    }

    /** @return list<AdministratorRecoveryCode> */
    private function newRecoveryCodes(DateTimeImmutable $createdAt, ?string $secondId = null): array
    {
        $codes = $this->recoveryCodes(260, 'new-recovery', $createdAt);
        if ($secondId !== null) {
            $original = $codes[1];
            $codes[1] = new AdministratorRecoveryCode(
                $secondId,
                $original->administratorId,
                $original->codeHash,
                $original->createdAt,
                null,
            );
        }

        return array_values($codes);
    }

    /** @return list<AdministratorRecoveryCode> */
    private function recoveryCodes(int $firstId, string $hashPrefix, DateTimeImmutable $createdAt): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = new AdministratorRecoveryCode(
                sprintf('01990d4a-0000-7000-8000-%012d', $firstId + $index),
                self::OWNER_ID,
                hash('sha256', sprintf('%s-%d', $hashPrefix, $index)),
                $createdAt,
                null,
            );
        }

        return $codes;
    }

    private function insertSessionsAndTokens(string $createdAt = '2026-09-06 00:02:00.000000'): void
    {
        foreach ([0, 1] as $index) {
            $revokedAt = $index === 0 ? null : '2026-09-06 00:03:30.000000';
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO administrator_sessions (
                        id, administrator_id, token_hash, authentication_version, created_at,
                        last_activity_at, idle_expires_at, absolute_expires_at, reauthenticated_at,
                        source_ip, user_agent, revoked_at
                    ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, NULL, ?, NULL, ?)
                    SQL,
                [
                    Uuid::fromString(sprintf('01990d4a-0000-7000-8000-%012d', 300 + $index))->toBinary(),
                    $this->ownerIdBinary(),
                    hash('sha256', sprintf('session-%d', $index), true),
                    $createdAt,
                    '2026-09-06 00:03:00.000000',
                    '2026-09-06 00:30:00.000000',
                    '2026-09-06 12:00:00.000000',
                    '::1',
                    $revokedAt,
                ],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO administrator_tokens (
                        id, administrator_id, purpose, token_hash, created_by_administrator_id,
                        created_at, expires_at, consumed_at, revoked_at
                    ) VALUES (?, ?, 'credential_reset', ?, NULL, ?, ?, NULL, ?)
                    SQL,
                [
                    Uuid::fromString(sprintf('01990d4a-0000-7000-8000-%012d', 310 + $index))->toBinary(),
                    $this->ownerIdBinary(),
                    hash('sha256', sprintf('token-%d', $index), true),
                    $createdAt,
                    '2026-09-06 01:00:00.000000',
                    $revokedAt,
                ],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
        }
    }

    private function ownerIdBinary(): string
    {
        return Uuid::fromString(self::OWNER_ID)->toBinary();
    }

    /** @return array<string, mixed> */
    private function recoveryState(): array
    {
        return [
            'owner' => $this->connection->fetchAssociative(
                'SELECT * FROM administrators WHERE id = ?',
                [$this->ownerIdBinary()],
                [ParameterType::BINARY],
            ),
            'credential' => $this->connection->fetchAssociative(
                'SELECT * FROM administrator_totp_credentials WHERE administrator_id = ?',
                [$this->ownerIdBinary()],
                [ParameterType::BINARY],
            ),
            'codes' => $this->connection->fetchAllAssociative(
                'SELECT * FROM administrator_recovery_codes WHERE administrator_id = ? ORDER BY id',
                [$this->ownerIdBinary()],
                [ParameterType::BINARY],
            ),
            'sessions' => $this->connection->fetchAllAssociative(
                'SELECT * FROM administrator_sessions WHERE administrator_id = ? ORDER BY id',
                [$this->ownerIdBinary()],
                [ParameterType::BINARY],
            ),
            'tokens' => $this->connection->fetchAllAssociative(
                'SELECT * FROM administrator_tokens WHERE administrator_id = ? ORDER BY id',
                [$this->ownerIdBinary()],
                [ParameterType::BINARY],
            ),
            'policy' => $this->connection->fetchAssociative('SELECT * FROM authentication_policies'),
        ];
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
