<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Infrastructure\Persistence\DoctrineAdministratorInvitationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorInvitationRepositoryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function itCreatesPendingAdministratorAndInvitationAtomically(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $administrator = new Administrator('01990d4a-0000-7000-8000-000000000180', 'invited.admin', '招待管理者', AdministratorRole::Administrator, AdministratorStatus::Pending, null, 1, null, null, null, null, null, $now, $now, 0);
        $token = new AdministratorToken('01990d4a-0000-7000-8000-000000000181', $administrator->id, AdministratorTokenPurpose::Invitation, hash('sha256', 'test-invitation-token'), null, 1, $now, $now->modify('+30 minutes'), null, null);

        (new DoctrineAdministratorInvitationRepository($this->connection))->create($administrator, $token);

        self::assertSame(1, $this->integer($this->connection->fetchOne('SELECT COUNT(*) FROM administrators WHERE id = ?', [Uuid::fromString($administrator->id)->toBinary()], [ParameterType::BINARY])));
        self::assertSame(1, $this->integer($this->connection->fetchOne('SELECT COUNT(*) FROM administrator_tokens WHERE administrator_id = ?', [Uuid::fromString($administrator->id)->toBinary()], [ParameterType::BINARY])));
    }

    private function integer(mixed $value): int
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
