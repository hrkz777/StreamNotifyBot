<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Infrastructure\Persistence\DoctrineAdministratorTokenRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineAdministratorTokenRepositoryTest extends KernelTestCase
{
    private const string TOKEN_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Connection $connection;
    private DoctrineAdministratorTokenRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAdministratorTokenRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itStoresOnlyTheTokenHashAndConsumesTheTokenOnce(): void
    {
        $token = $this->token();
        $this->repository->add($token);

        $storedHash = $this->connection->fetchOne(
            'SELECT token_hash FROM administrator_tokens WHERE id = ?',
            [Uuid::fromString($token->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsString($storedHash);
        self::assertSame(self::TOKEN_HASH, bin2hex($storedHash));

        $consumedAt = $this->dateTime('2026-09-04 00:10:00.123456');
        $consumed = $this->repository->consumeByHash(
            self::TOKEN_HASH,
            AdministratorTokenPurpose::InitialSetup,
            $consumedAt,
        );

        self::assertNotNull($consumed);
        self::assertSame($consumedAt, $consumed->consumedAt);
        self::assertNull(
            $this->repository->consumeByHash(
                self::TOKEN_HASH,
                AdministratorTokenPurpose::InitialSetup,
                $this->dateTime('2026-09-04 00:11:00.000000'),
            ),
        );
    }

    #[Test]
    public function itDoesNotConsumeAnExpiredToken(): void
    {
        $this->repository->add($this->token());

        $consumed = $this->repository->consumeByHash(
            self::TOKEN_HASH,
            AdministratorTokenPurpose::InitialSetup,
            $this->dateTime('2026-09-04 00:30:00.000000'),
        );

        self::assertNull($consumed);
    }

    #[Test]
    public function itDoesNotConsumeATokenForAnotherPurpose(): void
    {
        $this->repository->add($this->token());

        $consumed = $this->repository->consumeByHash(
            self::TOKEN_HASH,
            AdministratorTokenPurpose::CredentialReset,
            $this->dateTime('2026-09-04 00:10:00.000000'),
        );

        self::assertNull($consumed);
    }

    #[Test]
    public function itDoesNotConsumeARevokedToken(): void
    {
        $token = $this->token();
        $this->repository->add(new AdministratorToken(
            id: $token->id,
            administratorId: $token->administratorId,
            purpose: $token->purpose,
            tokenHash: $token->tokenHash,
            createdByAdministratorId: $token->createdByAdministratorId,
            createdAt: $token->createdAt,
            expiresAt: $token->expiresAt,
            consumedAt: null,
            revokedAt: $this->dateTime('2026-09-04 00:05:00.000000'),
        ));

        $consumed = $this->repository->consumeByHash(
            self::TOKEN_HASH,
            AdministratorTokenPurpose::InitialSetup,
            $this->dateTime('2026-09-04 00:10:00.000000'),
        );

        self::assertNull($consumed);
    }

    private function token(): AdministratorToken
    {
        return new AdministratorToken(
            id: '01990d4a-0000-7000-8000-000000000140',
            administratorId: null,
            purpose: AdministratorTokenPurpose::InitialSetup,
            tokenHash: self::TOKEN_HASH,
            createdByAdministratorId: null,
            createdAt: $this->dateTime('2026-09-04 00:00:00.000000'),
            expiresAt: $this->dateTime('2026-09-04 00:30:00.000000'),
            consumedAt: null,
            revokedAt: null,
        );
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
