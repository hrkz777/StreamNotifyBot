<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Subscription\WebhookCallbackUrl;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrineWebhookCallbackUrlRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineWebhookCallbackUrlRepositoryTest extends KernelTestCase
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
    public function itStoresAndReplacesTheSingletonCallbackUrl(): void
    {
        $repository = $this->repository();
        self::assertNull($repository->find());

        $repository->save(new WebhookCallbackUrl('https://notify.example/callback'));
        self::assertSame('https://notify.example/callback', $repository->find()?->value);

        $repository->save(new WebhookCallbackUrl('https://hooks.example'));
        self::assertSame('https://hooks.example', $repository->find()?->value);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM webhook_callback_urls'));
    }

    private function repository(): DoctrineWebhookCallbackUrlRepository
    {
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-12 04:05:06.123456+00:00');
            }
        };

        return new DoctrineWebhookCallbackUrlRepository($this->connection, $clock);
    }
}
