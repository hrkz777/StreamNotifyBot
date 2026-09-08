<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorSession;
use App\Domain\Administration\AdministratorSessionUnavailable;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorSessionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorSessionRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAdministratorSessionRepository $repository;
    /** @var list<string> */
    private array $administratorIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->repository = new DoctrineAdministratorSessionRepository($connection);
    }

    #[Test]
    public function itStartsAndTouchesAnActiveAdministratorSessionWithoutStoringTheRawSessionId(): void
    {
        $administrator = $this->persistAdministrator();
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $rawSessionId = 'raw-session-id-that-must-not-be-persisted';
        $tokenHash = hash('sha256', $rawSessionId);
        $session = $this->session(
            $administrator,
            $tokenHash,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
        );

        $this->repository->start($session);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    LOWER(HEX(token_hash)) AS token_hash,
                    authentication_version,
                    created_at,
                    last_activity_at,
                    idle_expires_at,
                    absolute_expires_at,
                    reauthenticated_at,
                    source_ip,
                    user_agent,
                    revoked_at
                FROM administrator_sessions
                WHERE id = ?
                SQL,
            [Uuid::fromString($session->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($row);
        self::assertSame($tokenHash, $row['token_hash']);
        self::assertNotSame($rawSessionId, $row['token_hash']);
        self::assertSame('::ffff:203.0.113.10', $row['source_ip']);
        self::assertSame('integration-test-agent', $row['user_agent']);
        self::assertNull($row['revoked_at']);

        $touchedAt = $createdAt->modify('+10 minutes');
        self::assertTrue($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $touchedAt,
            $touchedAt->modify('+30 minutes'),
        ));

        $updated = $this->connection->fetchAssociative(
            'SELECT last_activity_at, idle_expires_at FROM administrator_sessions WHERE id = ?',
            [Uuid::fromString($session->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($updated);
        self::assertSame('2026-09-07 00:10:00.000000', $updated['last_activity_at']);
        self::assertSame('2026-09-07 00:40:00.000000', $updated['idle_expires_at']);
    }

    #[Test]
    public function touchNeverExtendsTheIdleDeadlinePastTheAbsoluteDeadline(): void
    {
        $administrator = $this->persistAdministrator();
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $tokenHash = hash('sha256', 'absolute-cap-session');
        $session = $this->session(
            $administrator,
            $tokenHash,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+45 minutes'),
        );
        $this->repository->start($session);

        $touchedAt = $createdAt->modify('+20 minutes');
        self::assertTrue($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $touchedAt,
            $touchedAt->modify('+30 minutes'),
        ));

        self::assertSame(
            '2026-09-07 00:45:00.000000',
            $this->connection->fetchOne(
                'SELECT idle_expires_at FROM administrator_sessions WHERE id = ?',
                [Uuid::fromString($session->id)->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    #[Test]
    public function itMarksAnUnexpiredCurrentSessionAsReauthenticated(): void
    {
        $administrator = $this->persistAdministrator();
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $session = $this->session(
            $administrator,
            hash('sha256', 'reauthenticated-session'),
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
        );
        $this->repository->start($session);

        $reauthenticatedAt = $createdAt->modify('+10 minutes');
        self::assertTrue($this->repository->markReauthenticated(
            $session->tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $reauthenticatedAt,
        ));
        self::assertSame(
            '2026-09-07 00:10:00.000000',
            $this->connection->fetchOne(
                'SELECT reauthenticated_at FROM administrator_sessions WHERE id = ?',
                [Uuid::fromString($session->id)->toBinary()],
                [ParameterType::BINARY],
            ),
        );
    }

    #[Test]
    public function touchFailsClosedForGenerationChangeExpiryAndRevocation(): void
    {
        $administrator = $this->persistAdministrator();
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $tokenHash = hash('sha256', 'fail-closed-session');
        $session = $this->session(
            $administrator,
            $tokenHash,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
        );
        $this->repository->start($session);

        self::assertFalse($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion + 1,
            $createdAt->modify('+1 minute'),
            $createdAt->modify('+31 minutes'),
        ));

        $this->connection->executeStatement(
            'UPDATE administrators SET authentication_version = authentication_version + 1 WHERE id = ?',
            [Uuid::fromString($administrator->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertFalse($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $createdAt->modify('+2 minutes'),
            $createdAt->modify('+32 minutes'),
        ));

        $this->connection->executeStatement(
            'UPDATE administrators SET authentication_version = ? WHERE id = ?',
            [$administrator->authenticationVersion, Uuid::fromString($administrator->id)->toBinary()],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
        $this->repository->revoke($tokenHash, $createdAt->modify('+3 minutes'));
        self::assertFalse($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $createdAt->modify('+4 minutes'),
            $createdAt->modify('+34 minutes'),
        ));

        $this->connection->executeStatement(
            'UPDATE administrator_sessions SET revoked_at = NULL, idle_expires_at = ? WHERE id = ?',
            ['2026-09-07 00:04:00.000000', Uuid::fromString($session->id)->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
        self::assertFalse($this->repository->touch(
            $tokenHash,
            $administrator->id,
            $administrator->authenticationVersion,
            $createdAt->modify('+5 minutes'),
            $createdAt->modify('+35 minutes'),
        ));
    }

    #[Test]
    public function startRejectsAStaleAuthenticationVersion(): void
    {
        $administrator = $this->persistAdministrator();
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $session = new AdministratorSession(
            Uuid::v7()->toRfc4122(),
            $administrator->id,
            hash('sha256', 'stale-generation-session'),
            $administrator->authenticationVersion + 1,
            $createdAt,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
            $createdAt,
            '203.0.113.11',
            null,
            null,
        );

        $this->expectException(AdministratorSessionUnavailable::class);
        $this->repository->start($session);
    }

    private function persistAdministrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $id = Uuid::v7()->toRfc4122();
        $this->administratorIds[] = $id;
        $administrator = new Administrator(
            $id,
            'session.owner.'.substr($id, -12),
            'セッションテスト管理者',
            AdministratorRole::Owner,
            AdministratorStatus::Active,
            password_hash('test-only-password', PASSWORD_ARGON2ID),
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
        (new DoctrineAdministratorRepository($this->connection))->add($administrator);

        return $administrator;
    }

    private function session(
        Administrator $administrator,
        string $tokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $idleExpiresAt,
        DateTimeImmutable $absoluteExpiresAt,
    ): AdministratorSession {
        return new AdministratorSession(
            Uuid::v7()->toRfc4122(),
            $administrator->id,
            $tokenHash,
            $administrator->authenticationVersion,
            $createdAt,
            $createdAt,
            $idleExpiresAt,
            $absoluteExpiresAt,
            $createdAt,
            '203.0.113.10',
            'integration-test-agent',
            null,
        );
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->administratorIds as $administratorId) {
                $this->connection->executeStatement(
                    'DELETE FROM administrators WHERE id = ?',
                    [Uuid::fromString($administratorId)->toBinary()],
                    [ParameterType::BINARY],
                );
            }
        } finally {
            parent::tearDown();
        }
    }
}
