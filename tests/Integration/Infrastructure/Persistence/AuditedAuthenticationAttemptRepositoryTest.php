<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Persistence\AuditedAuthenticationAttemptRepository;
use App\Infrastructure\Persistence\DoctrineAuditLogRepository;
use App\Infrastructure\Persistence\DoctrineAuthenticationAttemptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AuditedAuthenticationAttemptRepositoryTest extends KernelTestCase
{
    private const string ATTEMPT_ID = '0199d534-0000-7000-8000-000000000010';
    private const string AUDIT_LOG_ID = '0199d534-0000-7000-8000-000000000011';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    #[Test]
    public function itRecordsTheAuthenticationAttemptAndAuditLogAtomically(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $repository = new AuditedAuthenticationAttemptRepository(
            $this->connection,
            new DoctrineAuthenticationAttemptRepository($this->connection),
            new DoctrineAuditLogRepository($this->connection),
            new class (self::AUDIT_LOG_ID) implements IdGenerator {
                public function __construct(private readonly string $id)
                {
                }

                public function generate(): string
                {
                    return $this->id;
                }
            },
        );

        $repository->record(new AuthenticationAttempt(
            self::ATTEMPT_ID,
            hash('sha256', 'system.owner'),
            '203.0.113.10',
            $attemptedAt,
            'failure',
            $attemptedAt->modify('+1 minute'),
        ));

        $auditLog = $this->connection->fetchAssociative(
            'SELECT action_code, result, correlation_id, source_ip, change_summary FROM audit_logs WHERE id = ?',
            [Uuid::fromString(self::AUDIT_LOG_ID)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($auditLog);
        self::assertSame('administrator.authentication', $auditLog['action_code']);
        self::assertSame('failed', $auditLog['result']);
        self::assertSame(self::ATTEMPT_ID, $auditLog['correlation_id']);
        self::assertSame('::ffff:203.0.113.10', $auditLog['source_ip']);
        self::assertSame('{"result":"failure","retry_delayed":true}', $auditLog['change_summary']);
    }

    protected function tearDown(): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM audit_logs WHERE id = ?', [Uuid::fromString(self::AUDIT_LOG_ID)->toBinary()], [ParameterType::BINARY]);
            $this->connection->executeStatement('DELETE FROM authentication_attempts WHERE id = ?', [Uuid::fromString(self::ATTEMPT_ID)->toBinary()], [ParameterType::BINARY]);
        } finally {
            parent::tearDown();
        }
    }
}
