<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Session;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerTest extends KernelTestCase
{
    /** @var list<string> */
    private array $sessionIds = [];
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    #[Test]
    public function itPersistsAndReadsBinarySessionDataThroughMariaDb(): void
    {
        $sessionId = $this->newSessionId();
        $payload = "authenticated|b:1;binary|s:3:\"\0\xFFx\";";
        $earliestExpiry = time() + 1800;
        $handler = $this->handler();

        self::assertTrue($handler->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertTrue($handler->write($sessionId, $payload));
        self::assertTrue($handler->close());

        $stored = $this->connection->fetchAssociative(
            'SELECT sess_data, sess_lifetime, sess_time FROM framework_sessions WHERE sess_id = ?',
            [$sessionId],
        );
        self::assertIsArray($stored);
        self::assertSame($payload, self::readLob($stored['sess_data'] ?? null));
        self::assertIsNumeric($stored['sess_lifetime'] ?? null);
        self::assertGreaterThanOrEqual($earliestExpiry, (int) $stored['sess_lifetime']);
        self::assertLessThanOrEqual(time() + 1800, (int) $stored['sess_lifetime']);
        self::assertIsNumeric($stored['sess_time'] ?? null);

        $reader = $this->handler();
        self::assertTrue($reader->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertSame($payload, $reader->read($sessionId));
        self::assertTrue($reader->close());
    }

    #[Test]
    public function itRefreshesAndDestroysTheStoredSession(): void
    {
        $sessionId = $this->newSessionId();
        $handler = $this->handler();
        self::assertTrue($handler->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertTrue($handler->write($sessionId, 'value|s:4:"test";'));
        self::assertTrue($handler->close());
        $this->connection->executeStatement(
            'UPDATE framework_sessions SET sess_lifetime = 1, sess_time = 1 WHERE sess_id = ?',
            [$sessionId],
        );

        $refresher = $this->handler();
        self::assertTrue($refresher->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertTrue($refresher->updateTimestamp($sessionId, 'ignored'));
        self::assertTrue($refresher->close());
        $stored = $this->connection->fetchAssociative(
            'SELECT sess_lifetime, sess_time FROM framework_sessions WHERE sess_id = ?',
            [$sessionId],
        );
        self::assertIsArray($stored);
        self::assertIsNumeric($stored['sess_lifetime'] ?? null);
        self::assertGreaterThanOrEqual(time() + 1799, (int) $stored['sess_lifetime']);
        self::assertIsNumeric($stored['sess_time'] ?? null);
        self::assertGreaterThan(1, (int) $stored['sess_time']);

        $destroyer = $this->handler();
        self::assertTrue($destroyer->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertTrue($destroyer->destroy($sessionId));
        self::assertTrue($destroyer->close());
        self::assertSame(0, self::readInteger($this->connection->fetchOne(
            'SELECT COUNT(*) FROM framework_sessions WHERE sess_id = ?',
            [$sessionId],
        )));
    }

    #[Test]
    public function garbageCollectionDeletesOnlyExpiredSessions(): void
    {
        $expiredSessionId = $this->newSessionId();
        $activeSessionId = $this->newSessionId();
        $now = time();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO framework_sessions (sess_id, sess_data, sess_lifetime, sess_time)
                VALUES (?, ?, ?, ?), (?, ?, ?, ?)
                SQL,
            [
                $expiredSessionId,
                'expired',
                $now - 1,
                $now - 1801,
                $activeSessionId,
                'active',
                $now + 1800,
                $now,
            ],
        );

        $handler = $this->handler();
        self::assertTrue($handler->open('', 'STREAMNOTIFYBOTSESSID'));
        self::assertSame(0, $handler->gc(1800));
        self::assertTrue($handler->close());

        self::assertSame([$activeSessionId], $this->connection->fetchFirstColumn(
            'SELECT sess_id FROM framework_sessions WHERE sess_id IN (?, ?) ORDER BY sess_id',
            [$expiredSessionId, $activeSessionId],
        ));
    }

    private function handler(): PdoSessionHandler
    {
        $handler = self::getContainer()->get('app.test_session.handler');
        self::assertInstanceOf(PdoSessionHandler::class, $handler);

        return $handler;
    }

    private function newSessionId(): string
    {
        $sessionId = 'test-'.bin2hex(random_bytes(24));
        $this->sessionIds[] = $sessionId;

        return $sessionId;
    }

    private static function readLob(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertIsString($contents);

            return $contents;
        }

        self::assertIsString($value);

        return $value;
    }

    private static function readInteger(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->sessionIds as $sessionId) {
                $this->connection->executeStatement(
                    'DELETE FROM framework_sessions WHERE sess_id = ?',
                    [$sessionId],
                );
            }
        } finally {
            parent::tearDown();
        }
    }
}
