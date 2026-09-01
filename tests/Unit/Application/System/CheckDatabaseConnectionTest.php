<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\System;

use App\Application\System\CheckDatabaseConnection;
use App\Domain\System\DatabaseConnectionProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckDatabaseConnectionTest extends TestCase
{
    #[Test]
    public function itReturnsTheProbeResult(): void
    {
        $probe = $this->createStub(DatabaseConnectionProbe::class);
        $probe->method('isAvailable')->willReturn(true);

        self::assertTrue((new CheckDatabaseConnection($probe))->isHealthy());
    }
}
