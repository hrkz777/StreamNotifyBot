<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogResult;
use App\Infrastructure\Persistence\DoctrineAuditLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAuditLogRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAuditLogRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAuditLogRepository($connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itReturnsTheLatestAuditLogsInDescendingOccurrenceOrder(): void
    {
        $this->repository->append($this->auditLog('01990d4a-0000-7000-8000-000000000601', '2099-09-08 00:01:00+00:00'));
        $this->repository->append($this->auditLog('01990d4a-0000-7000-8000-000000000602', '2099-09-08 00:02:00+00:00'));

        $logs = $this->repository->findLatest(1);

        self::assertCount(1, $logs);
        self::assertSame('01990d4a-0000-7000-8000-000000000602', $logs[0]->id);
        self::assertSame('administrator.deactivated', $logs[0]->actionCode);
        self::assertSame(AuditLogResult::Succeeded, $logs[0]->result);
        self::assertSame('2099-09-08 00:02:00.000000', $logs[0]->occurredAt->format('Y-m-d H:i:s.u'));
    }

    private function auditLog(string $id, string $occurredAt): AuditLog
    {
        return new AuditLog(
            $id,
            new DateTimeImmutable($occurredAt),
            null,
            null,
            'administrator.deactivated',
            'administrator',
            '01990d4a-0000-7000-8000-000000000600',
            AuditLogResult::Succeeded,
            $id,
            '203.0.113.10',
            'integration-test-agent',
            ['sessions_revoked' => true],
            null,
        );
    }
}
