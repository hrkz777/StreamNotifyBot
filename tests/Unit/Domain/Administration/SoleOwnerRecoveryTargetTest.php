<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\SoleOwnerRecoveryTarget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SoleOwnerRecoveryTargetTest extends TestCase
{
    #[Test]
    public function itAcceptsAValidSnapshot(): void
    {
        $target = new SoleOwnerRecoveryTarget(
            '01990d4a-0000-7000-8000-000000000150',
            'system.owner',
            '管理者',
            1,
        );

        self::assertSame(1, $target->lockVersion);
    }

    #[Test]
    public function itRejectsAnInvalidAdministratorId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SoleOwnerRecoveryTarget('not-a-uuid', 'system.owner', '管理者', 1);
    }

    #[Test]
    public function itRejectsAnEmptyIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SoleOwnerRecoveryTarget(
            '01990d4a-0000-7000-8000-000000000150',
            '',
            '管理者',
            1,
        );
    }

    #[Test]
    public function itRejectsANegativeLockVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SoleOwnerRecoveryTarget(
            '01990d4a-0000-7000-8000-000000000150',
            'system.owner',
            '管理者',
            -1,
        );
    }
}
