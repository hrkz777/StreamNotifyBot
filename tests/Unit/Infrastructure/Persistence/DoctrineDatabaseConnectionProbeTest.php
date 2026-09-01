<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence;

use App\Infrastructure\Persistence\DoctrineDatabaseConnectionProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DoctrineDatabaseConnectionProbeTest extends TestCase
{
    #[Test]
    #[DataProvider('successfulResultProvider')]
    public function itAcceptsDatabaseSuccessValues(int|string $result): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn($result);

        self::assertTrue((new DoctrineDatabaseConnectionProbe($connection))->isAvailable());
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function successfulResultProvider(): iterable
    {
        yield 'integer' => [1];
        yield 'numeric string' => ['1'];
    }

    #[Test]
    public function itRejectsAnUnexpectedResult(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertFalse((new DoctrineDatabaseConnectionProbe($connection))->isAvailable());
    }

    #[Test]
    public function itConvertsConnectionErrorsToAnUnavailableResult(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new RuntimeException('sensitive connection detail'));

        self::assertFalse((new DoctrineDatabaseConnectionProbe($connection))->isAvailable());
    }
}
