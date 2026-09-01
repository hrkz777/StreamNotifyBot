<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\System\CheckDatabaseConnection;
use App\Domain\System\DatabaseConnectionProbe;
use App\Presentation\Console\CheckDatabaseConnectionCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckDatabaseConnectionCommandTest extends TestCase
{
    #[Test]
    public function itSucceedsWhenTheDatabaseIsAvailable(): void
    {
        $commandTester = $this->createCommandTester(true);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertStringContainsString('データベースへ接続できました', $commandTester->getDisplay());
    }

    #[Test]
    public function itFailsWithoutDisclosingConnectionDetailsWhenTheDatabaseIsUnavailable(): void
    {
        $commandTester = $this->createCommandTester(false);

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString('データベースへ接続できませんでした', $commandTester->getDisplay());
        self::assertStringNotContainsString('DATABASE_URL', $commandTester->getDisplay());
    }

    private function createCommandTester(bool $isAvailable): CommandTester
    {
        $probe = $this->createStub(DatabaseConnectionProbe::class);
        $probe->method('isAvailable')->willReturn($isAvailable);

        return new CommandTester(
            new CheckDatabaseConnectionCommand(new CheckDatabaseConnection($probe)),
        );
    }
}
