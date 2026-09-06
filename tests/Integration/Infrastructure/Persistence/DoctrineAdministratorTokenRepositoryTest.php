<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\ConcurrentAdministratorTokenIssuance;
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
    private const string TARGET_TOKEN_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000141';

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
        $token = $this->initialSetupToken();
        $this->repository->add($token);

        $stored = $this->connection->fetchAssociative(
            'SELECT token_hash, authentication_version FROM administrator_tokens WHERE id = ?',
            [Uuid::fromString($token->id)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($stored);
        self::assertIsString($stored['token_hash']);
        self::assertSame(self::TOKEN_HASH, bin2hex($stored['token_hash']));
        self::assertNull($stored['authentication_version']);

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
    public function itStoresAndConsumesATargetTokenForTheCurrentAuthenticationVersion(): void
    {
        $this->insertAdministrator(authenticationVersion: 3);
        $token = $this->targetToken(authenticationVersion: 3);
        $this->repository->add($token);

        self::assertSame(
            3,
            $this->readInteger($this->connection->fetchOne(
                'SELECT authentication_version FROM administrator_tokens WHERE id = ?',
                [Uuid::fromString($token->id)->toBinary()],
                [ParameterType::BINARY],
            )),
        );

        $consumed = $this->repository->consumeByHash(
            self::TARGET_TOKEN_HASH,
            AdministratorTokenPurpose::Invitation,
            $this->dateTime('2026-09-04 00:10:00.000000'),
        );

        self::assertNotNull($consumed);
        self::assertSame(3, $consumed->authenticationVersion);
    }

    #[Test]
    public function itRejectsIssuanceWhenTheTargetAuthenticationVersionChanged(): void
    {
        $this->insertAdministrator(authenticationVersion: 2);

        try {
            $this->repository->add($this->targetToken(authenticationVersion: 1));
            self::fail('古い認証版のトークン発行が拒否されませんでした。');
        } catch (ConcurrentAdministratorTokenIssuance) {
            self::assertSame(
                0,
                $this->readInteger($this->connection->fetchOne(
                    'SELECT COUNT(*) FROM administrator_tokens WHERE token_hash = ?',
                    [hex2bin(self::TARGET_TOKEN_HASH)],
                    [ParameterType::BINARY],
                )),
            );
        }
    }

    #[Test]
    public function itDoesNotConsumeATargetTokenAfterTheAuthenticationVersionChanges(): void
    {
        $this->insertAdministrator(authenticationVersion: 1);
        $token = $this->targetToken(authenticationVersion: 1);
        $this->repository->add($token);
        $this->connection->executeStatement(
            'UPDATE administrators SET authentication_version = 2 WHERE id = ?',
            [Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary()],
            [ParameterType::BINARY],
        );

        self::assertNull($this->repository->consumeByHash(
            self::TARGET_TOKEN_HASH,
            AdministratorTokenPurpose::Invitation,
            $this->dateTime('2026-09-04 00:10:00.000000'),
        ));
        self::assertNull($this->connection->fetchOne(
            'SELECT consumed_at FROM administrator_tokens WHERE id = ?',
            [Uuid::fromString($token->id)->toBinary()],
            [ParameterType::BINARY],
        ));
    }

    #[Test]
    public function itDoesNotConsumeAnExpiredToken(): void
    {
        $this->repository->add($this->initialSetupToken());

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
        $this->repository->add($this->initialSetupToken());

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
        $token = $this->initialSetupToken();
        $this->repository->add(new AdministratorToken(
            id: $token->id,
            administratorId: $token->administratorId,
            purpose: $token->purpose,
            tokenHash: $token->tokenHash,
            createdByAdministratorId: $token->createdByAdministratorId,
            authenticationVersion: $token->authenticationVersion,
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

    private function initialSetupToken(): AdministratorToken
    {
        return new AdministratorToken(
            id: '01990d4a-0000-7000-8000-000000000140',
            administratorId: null,
            purpose: AdministratorTokenPurpose::InitialSetup,
            tokenHash: self::TOKEN_HASH,
            createdByAdministratorId: null,
            authenticationVersion: null,
            createdAt: $this->dateTime('2026-09-04 00:00:00.000000'),
            expiresAt: $this->dateTime('2026-09-04 00:30:00.000000'),
            consumedAt: null,
            revokedAt: null,
        );
    }

    private function targetToken(int $authenticationVersion): AdministratorToken
    {
        return new AdministratorToken(
            id: '01990d4a-0000-7000-8000-000000000142',
            administratorId: self::ADMINISTRATOR_ID,
            purpose: AdministratorTokenPurpose::Invitation,
            tokenHash: self::TARGET_TOKEN_HASH,
            createdByAdministratorId: null,
            authenticationVersion: $authenticationVersion,
            createdAt: $this->dateTime('2026-09-04 00:00:00.000000'),
            expiresAt: $this->dateTime('2026-09-04 00:30:00.000000'),
            consumedAt: null,
            revokedAt: null,
        );
    }

    private function insertAdministrator(int $authenticationVersion): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrators (
                    id,
                    login_id,
                    display_name,
                    role,
                    status,
                    password_hash,
                    authentication_version,
                    password_changed_at,
                    totp_enrolled_at,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (?, ?, ?, 'administrator', 'pending', NULL, ?, NULL, NULL, ?, ?, 0)
                SQL,
            [
                Uuid::fromString(self::ADMINISTRATOR_ID)->toBinary(),
                'pending.admin',
                'テスト管理者',
                $authenticationVersion,
                '2026-09-04 00:00:00.000000',
                '2026-09-04 00:00:00.000000',
            ],
            [
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    private function readInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        self::fail('整数値を取得できませんでした。');
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
