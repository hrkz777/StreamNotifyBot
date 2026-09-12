<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Domain\Administration\AuditLogRepository;
use App\Domain\System\Clock;
use App\Domain\System\OperationalSetting;
use App\Domain\System\OperationalSettingRepository;
use App\Presentation\Console\PurgeAuditLogsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeAuditLogsCommandTest extends TestCase
{
    #[Test]
    public function itDoesNotDeleteWithoutAnExplicitExecuteOption(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::never())->method('deleteBefore');
        $tester = new CommandTester(new PurgeAuditLogsCommand($repository, $this->settings(365), $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('--execute', $tester->getDisplay());
    }

    #[Test]
    public function itDeletesOnlyLogsOlderThanTheConfiguredRetention(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())
            ->method('deleteBefore')
            ->with(new DateTimeImmutable('2026-03-12 00:00:00+00:00'))
            ->willReturn(3);
        $tester = new CommandTester(new PurgeAuditLogsCommand($repository, $this->settings(180), $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('3件', $tester->getDisplay());
    }

    #[Test]
    public function itPrioritizesAnExplicitRetentionOption(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())
            ->method('deleteBefore')
            ->with(new DateTimeImmutable('2025-12-12 00:00:00+00:00'))
            ->willReturn(0);
        $tester = new CommandTester(new PurgeAuditLogsCommand($repository, $this->settings(180), $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true, '--retention-days' => '270']));
    }

    private function settings(int $retentionDays): OperationalSettingRepository
    {
        $repository = $this->createStub(OperationalSettingRepository::class);
        $repository->method('findAll')->willReturn([
            new OperationalSetting('retention_audit_logs', $retentionDays, new DateTimeImmutable('2026-09-08 00:00:00+00:00'), 0),
        ]);

        return $repository;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:00:00+00:00'));

        return $clock;
    }
}
