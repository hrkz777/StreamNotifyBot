<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AuthenticationAttempt;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticationAttemptTest extends TestCase
{
    #[Test]
    public function itAcceptsAnAuthenticationAttemptWithoutTheRawLoginIdentifier(): void
    {
        $attemptedAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $attempt = new AuthenticationAttempt(
            '0199d534-0000-7000-8000-000000000001',
            hash('sha256', 'owner'),
            '2001:db8::1',
            $attemptedAt,
            'failure',
            $attemptedAt->modify('+1 minute'),
        );

        self::assertSame('failure', $attempt->result);
        self::assertSame('2001:db8::1', $attempt->sourceIp);
    }

    #[Test]
    public function itRejectsAHashThatCouldContainARawLoginIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthenticationAttempt(
            '0199d534-0000-7000-8000-000000000001',
            'owner',
            '203.0.113.10',
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            'failure',
            null,
        );
    }

    #[Test]
    public function itRejectsARetryDeadlineBeforeTheAttempt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $attemptedAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        new AuthenticationAttempt(
            '0199d534-0000-7000-8000-000000000001',
            hash('sha256', 'owner'),
            '203.0.113.10',
            $attemptedAt,
            'failure',
            $attemptedAt->modify('-1 second'),
        );
    }

    #[Test]
    public function itRejectsARetryDeadlineForASuccessfulAttempt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $attemptedAt = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        new AuthenticationAttempt(
            '0199d534-0000-7000-8000-000000000001',
            hash('sha256', 'owner'),
            '203.0.113.10',
            $attemptedAt,
            'success',
            $attemptedAt->modify('+1 minute'),
        );
    }
}
