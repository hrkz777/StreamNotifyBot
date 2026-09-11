<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Security\EncryptedSecret;
use App\Domain\Stream\NotificationDestination;
use App\Domain\Stream\StreamNotificationType;
use App\Infrastructure\Persistence\DoctrineNotificationDestinationRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineNotificationDestinationRepositoryTest extends KernelTestCase
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
    public function itCountsPersistedNotificationDestinations(): void
    {
        $repository = new DoctrineNotificationDestinationRepository($this->connection);
        self::assertSame(0, $repository->countDestinations());

        $repository->save(new NotificationDestination(
            '01990d4a-0000-7000-8000-000000000801',
            StreamNotificationType::Started,
            new EncryptedSecret(str_repeat('a', 16), str_repeat('b', 24), 'test-key'),
            true,
        ));

        self::assertSame(1, $repository->countDestinations());
    }
}
