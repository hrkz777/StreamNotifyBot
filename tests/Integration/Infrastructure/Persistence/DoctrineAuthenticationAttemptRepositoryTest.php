<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationAttempt;
use App\Infrastructure\Persistence\DoctrineAuthenticationAttemptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAuthenticationAttemptRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAuthenticationAttemptRepository $repository;
    /** @var list<string> */
    private array $attemptIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->repository = new DoctrineAuthenticationAttemptRepository($connection);
    }

    #[Test]
    public function itRecordsOnlyAHashOfTheLoginIdentifier(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00');
        $loginIdentifier = 'rate-limit.owner';
        $attempt = $this->authenticationAttempt(
            $loginIdentifier,
            '203.0.113.10',
            $attemptedAt,
            'failure',
            $attemptedAt->modify('+1 minute'),
        );

        $this->repository->record($attempt);

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(login_identifier_hash)) AS login_identifier_hash, source_ip, attempted_at, result, retry_after FROM authentication_attempts WHERE id = ?',
            [Uuid::fromString($attempt->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $loginIdentifier), $row['login_identifier_hash']);
        self::assertNotSame($loginIdentifier, $row['login_identifier_hash']);
        self::assertSame('::ffff:203.0.113.10', $row['source_ip']);
        self::assertSame('2026-09-08 00:01:00.123456', $row['attempted_at']);
        self::assertSame('failure', $row['result']);
        self::assertSame('2026-09-08 00:02:00.123456', $row['retry_after']);
    }

    #[Test]
    public function itCountsOnlyRecentFailuresForTheSameLoginIdentifierAndSourceIp(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:10:00+00:00');
        $loginIdentifierHash = hash('sha256', 'rate-limit.owner');
        $this->repository->record($this->attemptFromHash($loginIdentifierHash, '203.0.113.10', $now->modify('-4 minutes'), 'failure'));
        $this->repository->record($this->attemptFromHash($loginIdentifierHash, '203.0.113.10', $now->modify('-2 minutes'), 'failure'));
        $this->repository->record($this->attemptFromHash($loginIdentifierHash, '203.0.113.10', $now->modify('-1 minute'), 'success'));
        $this->repository->record($this->attemptFromHash($loginIdentifierHash, '203.0.113.11', $now->modify('-1 minute'), 'failure'));
        $this->repository->record($this->attemptFromHash(hash('sha256', 'another.owner'), '203.0.113.10', $now->modify('-1 minute'), 'failure'));
        $this->repository->record($this->attemptFromHash($loginIdentifierHash, '203.0.113.10', $now->modify('-6 minutes'), 'failure'));

        self::assertSame(2, $this->repository->countFailuresSince(
            $loginIdentifierHash,
            '203.0.113.10',
            $now->modify('-5 minutes'),
        ));
    }

    #[Test]
    public function itFindsOnlyAnActiveRetryDeadlineWithinTheFailureWindow(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:10:00+00:00');
        $loginIdentifierHash = hash('sha256', 'rate-limit.owner');
        $this->repository->record($this->attemptFromHash(
            $loginIdentifierHash,
            '203.0.113.10',
            $now->modify('-2 minutes'),
            'failure',
            $now->modify('+1 minute'),
        ));
        $this->repository->record($this->attemptFromHash(
            $loginIdentifierHash,
            '203.0.113.10',
            $now->modify('-1 minute'),
            'failure',
            $now->modify('+2 minutes'),
        ));
        $this->repository->record($this->attemptFromHash(
            $loginIdentifierHash,
            '203.0.113.10',
            $now->modify('-30 minutes'),
            'failure',
            $now->modify('+3 minutes'),
        ));
        $this->repository->record($this->attemptFromHash(
            $loginIdentifierHash,
            '203.0.113.11',
            $now->modify('-1 minute'),
            'failure',
            $now->modify('+4 minutes'),
        ));

        self::assertSame(
            '2026-09-08 00:12:00.000000',
            $this->repository->findRetryAfterSince(
                $loginIdentifierHash,
                '203.0.113.10',
                $now->modify('-15 minutes'),
                $now,
            )?->format('Y-m-d H:i:s.u'),
        );
    }

    private function authenticationAttempt(
        string $loginIdentifier,
        string $sourceIp,
        DateTimeImmutable $attemptedAt,
        string $result,
        ?DateTimeImmutable $retryAfter = null,
    ): AuthenticationAttempt {
        return $this->attemptFromHash(hash('sha256', $loginIdentifier), $sourceIp, $attemptedAt, $result, $retryAfter);
    }

    private function attemptFromHash(
        string $loginIdentifierHash,
        string $sourceIp,
        DateTimeImmutable $attemptedAt,
        string $result,
        ?DateTimeImmutable $retryAfter = null,
    ): AuthenticationAttempt {
        $attempt = new AuthenticationAttempt(
            Uuid::v7()->toRfc4122(),
            $loginIdentifierHash,
            $sourceIp,
            $attemptedAt,
            $result,
            $retryAfter,
        );
        $this->attemptIds[] = $attempt->id;

        return $attempt;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->attemptIds as $attemptId) {
                $this->connection->executeStatement(
                    'DELETE FROM authentication_attempts WHERE id = ?',
                    [Uuid::fromString($attemptId)->toBinary()],
                    [ParameterType::BINARY],
                );
            }
        } finally {
            parent::tearDown();
        }
    }
}
