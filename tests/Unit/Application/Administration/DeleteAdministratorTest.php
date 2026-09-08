<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\DeleteAdministrator;
use App\Domain\Administration\AdministratorDeletionRepository;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeleteAdministratorTest extends TestCase
{
    #[Test]
    public function itUsesTheCurrentTimeForDeletion(): void
    {
        $now = new DateTimeImmutable('2026-09-08 00:01:00.123456+00:00');
        $repository = $this->createMock(AdministratorDeletionRepository::class);
        $repository->expects(self::once())
            ->method('delete')
            ->with('01990d4a-0000-7000-8000-000000000510', $now)
            ->willReturn(true);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);

        self::assertTrue((new DeleteAdministrator($repository, $clock))->delete('01990d4a-0000-7000-8000-000000000510'));
    }
}
