<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogRepository;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Persistence\AuditedSoleOwnerCredentialRecoveryRepository;
use App\Infrastructure\Persistence\DoctrineAuditLogRepository;
use App\Infrastructure\Persistence\DoctrineInitialOwnerRepository;
use App\Infrastructure\Persistence\DoctrineSoleOwnerCredentialRecoveryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AuditedSoleOwnerCredentialRecoveryRepositoryTest extends KernelTestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000450';
    private const string AUDIT_LOG_ID = '01990d4a-0000-7000-8000-000000000451';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'UPDATE authentication_policies SET updated_at = ?',
            ['2026-09-06 00:00:00.000000'],
            [ParameterType::STRING],
        );
        $this->createInitialOwner();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itPersistsASanitizedAuditLogWithTheRecovery(): void
    {
        $repository = $this->repository(new DoctrineAuditLogRepository($this->connection));
        $target = $repository->findTarget();
        $recoveredAt = new DateTimeImmutable('2026-09-07 00:10:00.123456+00:00');

        $repository->recover(
            $target,
            password_hash('new-owner-password', PASSWORD_ARGON2ID),
            $this->newCredential(),
            $this->newRecoveryCodes($recoveredAt),
            $recoveredAt,
        );

        $auditLog = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, occurred_at, actor_administrator_id, actor_display_snapshot, action_code,
                       target_type, target_id, result, correlation_id, source_ip, user_agent,
                       change_summary, error_code
                FROM audit_logs
                SQL,
        );
        self::assertIsArray($auditLog);
        self::assertIsString($auditLog['id']);
        self::assertSame(self::AUDIT_LOG_ID, Uuid::fromBinary($auditLog['id'])->toRfc4122());
        self::assertSame('2026-09-07 00:10:00.123456', $auditLog['occurred_at']);
        self::assertNull($auditLog['actor_administrator_id']);
        self::assertNull($auditLog['actor_display_snapshot']);
        self::assertSame('administrator.sole_owner_credentials_recovered', $auditLog['action_code']);
        self::assertSame('administrator', $auditLog['target_type']);
        self::assertSame(self::OWNER_ID, $auditLog['target_id']);
        self::assertSame('succeeded', $auditLog['result']);
        self::assertSame(self::AUDIT_LOG_ID, $auditLog['correlation_id']);
        self::assertNull($auditLog['source_ip']);
        self::assertNull($auditLog['user_agent']);
        self::assertSame(
            '{"credential_types":["password","totp","recovery_codes"],"authentication_version_incremented":true,"sessions_revoked":true,"tokens_revoked":true}',
            $auditLog['change_summary'],
        );
        self::assertNull($auditLog['error_code']);

        self::assertSame(2, self::readInteger($this->connection->fetchOne(
            'SELECT authentication_version FROM administrators WHERE id = ?',
            [$this->ownerIdBinary()],
            [ParameterType::BINARY],
        )));
    }

    #[Test]
    public function itRollsBackTheRecoveryWhenTheAuditAppendFails(): void
    {
        $before = $this->recoveryState();
        $repository = $this->repository(new class implements AuditLogRepository {
            public function append(AuditLog $auditLog): void
            {
                throw new RuntimeException('監査ログを保存できません。');
            }
        });
        $target = $repository->findTarget();
        $recoveredAt = new DateTimeImmutable('2026-09-07 00:10:00.123456+00:00');

        try {
            $repository->recover(
                $target,
                password_hash('new-owner-password', PASSWORD_ARGON2ID),
                $this->newCredential(),
                $this->newRecoveryCodes($recoveredAt),
                $recoveredAt,
            );
            self::fail('監査ログ保存失敗時に資格情報回復が成功しました。');
        } catch (RuntimeException $exception) {
            self::assertSame('監査ログを保存できません。', $exception->getMessage());
        }

        self::assertSame($before, $this->recoveryState());
    }

    #[Test]
    public function itDecoratesTheSoleOwnerRecoveryRepositoryService(): void
    {
        $repository = self::getContainer()->get(SoleOwnerCredentialRecoveryRepository::class);

        self::assertInstanceOf(AuditedSoleOwnerCredentialRecoveryRepository::class, $repository);
    }

    private function repository(AuditLogRepository $auditLogRepository): AuditedSoleOwnerCredentialRecoveryRepository
    {
        return new AuditedSoleOwnerCredentialRecoveryRepository(
            $this->connection,
            new DoctrineSoleOwnerCredentialRecoveryRepository($this->connection),
            $auditLogRepository,
            new class implements IdGenerator {
                public function generate(): string
                {
                    return AuditedSoleOwnerCredentialRecoveryRepositoryTest::AUDIT_LOG_ID;
                }
            },
        );
    }

    private function createInitialOwner(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-06 00:01:00+00:00');
        $completedAt = new DateTimeImmutable('2026-09-06 00:02:00+00:00');
        (new DoctrineInitialOwnerRepository($this->connection))->create(
            new Administrator(
                self::OWNER_ID,
                'system.owner',
                '管理者',
                AdministratorRole::Owner,
                AdministratorStatus::Pending,
                password_hash('old-owner-password', PASSWORD_ARGON2ID),
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
        return $this->recoveryCodes(460, 'old-recovery', $createdAt);
    }

    /** @return list<AdministratorRecoveryCode> */
    private function newRecoveryCodes(DateTimeImmutable $createdAt): array
    {
        return $this->recoveryCodes(470, 'new-recovery', $createdAt);
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
            'audit_logs' => $this->connection->fetchAllAssociative('SELECT * FROM audit_logs ORDER BY id'),
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
