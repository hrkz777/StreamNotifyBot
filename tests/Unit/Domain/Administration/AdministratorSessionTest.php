<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AdministratorSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorSessionTest extends TestCase
{
    #[Test]
    public function itAcceptsAConsistentSessionSnapshot(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $session = new AdministratorSession(
            '0199b3b2-0000-7000-8000-000000000001',
            '0199b3b2-0000-7000-8000-000000000002',
            hash('sha256', 'session-id'),
            1,
            $createdAt,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
            $createdAt,
            '2001:db8::1',
            'StreamNotifyBot test browser',
            null,
        );

        self::assertSame(1, $session->authenticationVersion);
        self::assertSame('2001:db8::1', $session->sourceIp);
    }

    #[Test]
    public function itRejectsAnInvalidTokenHash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        new AdministratorSession(
            '0199b3b2-0000-7000-8000-000000000001',
            '0199b3b2-0000-7000-8000-000000000002',
            'not-a-sha256-hash',
            1,
            $createdAt,
            $createdAt,
            $createdAt->modify('+30 minutes'),
            $createdAt->modify('+12 hours'),
            $createdAt,
            '127.0.0.1',
            null,
            null,
        );
    }

    #[Test]
    public function itRejectsAnIdleDeadlineAfterTheAbsoluteDeadline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $createdAt = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        new AdministratorSession(
            '0199b3b2-0000-7000-8000-000000000001',
            '0199b3b2-0000-7000-8000-000000000002',
            hash('sha256', 'session-id'),
            1,
            $createdAt,
            $createdAt,
            $createdAt->modify('+2 hours'),
            $createdAt->modify('+1 hour'),
            $createdAt,
            '127.0.0.1',
            null,
            null,
        );
    }
}
