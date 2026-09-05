<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\EncryptedSecret;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use App\Infrastructure\Persistence\DoctrineAdministratorTotpCredentialRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAdministratorTotpCredentialRepositoryTest extends KernelTestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000150';

    private Connection $connection;
    private DoctrineAdministratorTotpCredentialRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAdministratorTotpCredentialRepository($this->connection);

        (new DoctrineAdministratorRepository($this->connection))->add($this->administrator());
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itStoresAndLoadsBinaryEncryptedCredentials(): void
    {
        $credential = $this->credential();
        $this->repository->add($credential);

        $stored = $this->repository->findByAdministratorId(self::ADMINISTRATOR_ID);

        self::assertNotNull($stored);
        self::assertSame($credential->administratorId, $stored->administratorId);
        self::assertSame($credential->encryptedSecret->encryptedValue, $stored->encryptedSecret->encryptedValue);
        self::assertSame($credential->encryptedSecret->nonce, $stored->encryptedSecret->nonce);
        self::assertSame($credential->encryptedSecret->keyId, $stored->encryptedSecret->keyId);
        self::assertSame($credential->encryptedSecret->formatVersion, $stored->encryptedSecret->formatVersion);
        self::assertNull($stored->lastAcceptedTimeStep);
    }

    #[Test]
    public function itReturnsNullWhenTheCredentialDoesNotExist(): void
    {
        self::assertNull(
            $this->repository->findByAdministratorId('01990d4a-0000-7000-8000-000000000151'),
        );
    }

    #[Test]
    public function itAtomicallyRejectsReusedAndOlderTimeSteps(): void
    {
        $this->repository->add($this->credential());

        self::assertTrue($this->repository->acceptTimeStep(self::ADMINISTRATOR_ID, 100));
        self::assertFalse($this->repository->acceptTimeStep(self::ADMINISTRATOR_ID, 100));
        self::assertFalse($this->repository->acceptTimeStep(self::ADMINISTRATOR_ID, 99));
        self::assertTrue($this->repository->acceptTimeStep(self::ADMINISTRATOR_ID, 101));

        $stored = $this->repository->findByAdministratorId(self::ADMINISTRATOR_ID);
        self::assertNotNull($stored);
        self::assertSame(101, $stored->lastAcceptedTimeStep);
    }

    #[Test]
    public function itRejectsANegativeTimeStep(): void
    {
        $this->repository->add($this->credential());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('受理するTOTP時刻ステップは0以上で指定してください。');

        $this->repository->acceptTimeStep(self::ADMINISTRATOR_ID, -1);
    }

    private function credential(): AdministratorTotpCredential
    {
        return new AdministratorTotpCredential(
            self::ADMINISTRATOR_ID,
            new EncryptedSecret(
                "\0".str_repeat("\xFF", EncryptedSecret::AUTHENTICATION_TAG_BYTE_LENGTH),
                implode('', array_map(chr(...), range(0, EncryptedSecret::NONCE_BYTE_LENGTH - 1))),
                'primary',
            ),
            null,
        );
    }

    private function administrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-06 00:00:00.000000', new DateTimeZone('UTC'));

        return new Administrator(
            id: self::ADMINISTRATOR_ID,
            loginId: 'system.owner',
            displayName: '管理者',
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Pending,
            passwordHash: null,
            authenticationVersion: 1,
            passwordChangedAt: null,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $now,
            updatedAt: $now,
            lockVersion: 0,
        );
    }
}
