<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Security\EncryptedSecret;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrinePlatformApiCredentialRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrinePlatformApiCredentialRepositoryTest extends KernelTestCase
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
    public function itStoresAndReplacesAnEncryptedCredentialForEachPlatform(): void
    {
        $repository = $this->repository();
        $credential = new PlatformApiCredential(
            '01990d4a-0000-7000-8000-000000001501',
            Platform::Twitch,
            new EncryptedSecret(str_repeat('a', 16), str_repeat('b', 24), 'primary'),
        );

        $repository->save($credential);
        $stored = $repository->findByPlatform(Platform::Twitch);

        self::assertNotNull($stored);
        self::assertSame($credential->id, $stored->id);
        self::assertSame($credential->encryptedValue->encryptedValue, $stored->encryptedValue->encryptedValue);
        self::assertSame($credential->encryptedValue->nonce, $stored->encryptedValue->nonce);
        self::assertSame($credential->encryptedValue->keyId, $stored->encryptedValue->keyId);

        $replacement = new PlatformApiCredential(
            '01990d4a-0000-7000-8000-000000001502',
            Platform::Twitch,
            new EncryptedSecret(str_repeat('c', 16), str_repeat('d', 24), 'rotated'),
        );
        $repository->save($replacement);
        $replaced = $repository->findByPlatform(Platform::Twitch);

        self::assertNotNull($replaced);
        self::assertSame($replacement->id, $replaced->id);
        self::assertSame($replacement->encryptedValue->encryptedValue, $replaced->encryptedValue->encryptedValue);
        self::assertNull($repository->findByPlatform(Platform::YouTube));
    }

    private function repository(): DoctrinePlatformApiCredentialRepository
    {
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-12 03:04:05.123456+00:00');
            }
        };

        return new DoctrinePlatformApiCredentialRepository($this->connection, $clock);
    }
}
