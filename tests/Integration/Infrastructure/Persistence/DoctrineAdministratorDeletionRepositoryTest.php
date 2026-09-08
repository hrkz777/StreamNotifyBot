<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Persistence\DoctrineAdministratorDeletionRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorDeletionRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAdministratorRepository $administrators;
    private DoctrineAdministratorDeletionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->administrators = new DoctrineAdministratorRepository($connection);
        $this->repository = new DoctrineAdministratorDeletionRepository($connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itDoesNotDeleteTheLastActiveOwner(): void
    {
        $owner = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000510', 'sole.owner', AdministratorRole::Owner);
        $this->administrators->add($owner);

        self::assertFalse($this->repository->delete($owner->id, new DateTimeImmutable('2026-09-08 00:01:00+00:00')));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM administrators WHERE id = ?', [$this->binaryId($owner->id)], [ParameterType::BINARY]));
    }

    #[Test]
    public function itDeletesCredentialsAndRecoveryCodesWhenAnotherActiveOwnerRemains(): void
    {
        $first = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000511', 'first.owner', AdministratorRole::Owner);
        $second = $this->activeAdministrator('01990d4a-0000-7000-8000-000000000512', 'second.owner', AdministratorRole::Owner);
        $this->administrators->add($first);
        $this->administrators->add($second);
        $this->addTotpCredential($first->id);
        $this->addRecoveryCode($first->id);

        self::assertTrue($this->repository->delete($first->id, new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00')));

        $row = $this->connection->fetchAssociative('SELECT status, password_hash, password_changed_at, totp_enrolled_at, deleted_at, authentication_version, lock_version FROM administrators WHERE id = ?', [$this->binaryId($first->id)], [ParameterType::BINARY]);
        self::assertIsArray($row);
        self::assertSame('deleted', $row['status']);
        self::assertNull($row['password_hash']);
        self::assertNull($row['password_changed_at']);
        self::assertNull($row['totp_enrolled_at']);
        self::assertSame('2026-09-08 00:01:00.123456', $row['deleted_at']);
        self::assertSame(2, self::integer($row['authentication_version']));
        self::assertSame(1, self::integer($row['lock_version']));
        self::assertSame(0, self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM administrator_totp_credentials WHERE administrator_id = ?', [$this->binaryId($first->id)], [ParameterType::BINARY])));
        self::assertSame(0, self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM administrator_recovery_codes WHERE administrator_id = ?', [$this->binaryId($first->id)], [ParameterType::BINARY])));
    }

    private function activeAdministrator(string $id, string $loginId, AdministratorRole $role): Administrator
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $passwordHash = password_hash('test-only-password', PASSWORD_ARGON2ID);

        return new Administrator($id, $loginId, '管理者', $role, AdministratorStatus::Active, $passwordHash, 1, $now, $now, null, null, null, $now, $now, 0);
    }

    private function addTotpCredential(string $administratorId): void
    {
        $this->connection->insert('administrator_totp_credentials', [
            'administrator_id' => $this->binaryId($administratorId),
            'encrypted_value' => 'test-ciphertext',
            'encryption_nonce' => str_repeat('a', 24),
            'encryption_key_id' => 'test-key',
            'encryption_format_version' => 1,
            'last_accepted_time_step' => null,
        ], [
            'administrator_id' => ParameterType::BINARY,
            'encrypted_value' => ParameterType::BINARY,
            'encryption_nonce' => ParameterType::BINARY,
            'encryption_key_id' => ParameterType::STRING,
            'encryption_format_version' => ParameterType::INTEGER,
            'last_accepted_time_step' => ParameterType::NULL,
        ]);
    }

    private function addRecoveryCode(string $administratorId): void
    {
        $this->connection->insert('administrator_recovery_codes', [
            'id' => $this->binaryId('01990d4a-0000-7000-8000-000000000513'),
            'administrator_id' => $this->binaryId($administratorId),
            'code_hash' => hash('sha256', 'test-recovery-code', true),
            'created_at' => '2026-09-08 00:00:00.000000',
            'used_at' => null,
        ], [
            'id' => ParameterType::BINARY,
            'administrator_id' => ParameterType::BINARY,
            'code_hash' => ParameterType::BINARY,
            'created_at' => ParameterType::STRING,
            'used_at' => ParameterType::NULL,
        ]);
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
