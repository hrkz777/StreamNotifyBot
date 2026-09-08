<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\System\Clock;
use App\Presentation\Console\PurgeAuthenticationAttemptsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeAuthenticationAttemptsCommandTest extends TestCase
{
    #[Test]
    public function itDoesNotDeleteWithoutAnExplicitExecuteOption(): void
    {
        $repository = $this->createMock(AuthenticationAttemptRepository::class);
        $repository->expects(self::never())->method('deleteBefore');
        $tester = new CommandTester(new PurgeAuthenticationAttemptsCommand($repository, $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('--execute', $tester->getDisplay());
    }

    #[Test]
    public function itDeletesOnlyAttemptsOlderThanTheDefault365DayRetention(): void
    {
        $repository = $this->createMock(AuthenticationAttemptRepository::class);
        $repository->expects(self::once())
            ->method('deleteBefore')
            ->with(new DateTimeImmutable('2025-09-08 00:00:00+00:00'))
            ->willReturn(3);
        $tester = new CommandTester(new PurgeAuthenticationAttemptsCommand($repository, $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('3件', $tester->getDisplay());
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:00:00+00:00'));

        return $clock;
    }
}
