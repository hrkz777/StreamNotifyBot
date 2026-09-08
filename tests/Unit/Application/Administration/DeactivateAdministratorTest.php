<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\DeactivateAdministrator;
use App\Domain\Administration\AdministratorDeactivationRepository;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeactivateAdministratorTest extends TestCase
{
    #[Test]
    public function itUsesTheCurrentTimeForDeactivation(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00');
        $repository = $this->createMock(AdministratorDeactivationRepository::class);
        $repository->expects(self::once())
            ->method('deactivate')
            ->with('01990d4a-0000-7000-8000-000000000500', $now)
            ->willReturn(true);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        self::assertTrue((new DeactivateAdministrator($repository, $clock))->deactivate('01990d4a-0000-7000-8000-000000000500'));
    }
}
