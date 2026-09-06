<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AdministratorRecoveryCode;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorRecoveryCodeTest extends TestCase
{
    #[Test]
    public function itAcceptsAnUnusedHashedCode(): void
    {
        $code = new AdministratorRecoveryCode(
            '01990d4a-0000-7000-8000-000000000160',
            '01990d4a-0000-7000-8000-000000000150',
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-06 00:00:00+00:00'),
            null,
        );

        self::assertNull($code->usedAt);
    }

    #[Test]
    public function itRejectsAUsedTimeBeforeCreation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('回復コード使用日時は作成日時以降で指定してください。');

        new AdministratorRecoveryCode(
            '01990d4a-0000-7000-8000-000000000160',
            '01990d4a-0000-7000-8000-000000000150',
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-06 00:00:01+00:00'),
            new DateTimeImmutable('2026-09-06 00:00:00+00:00'),
        );
    }
}
