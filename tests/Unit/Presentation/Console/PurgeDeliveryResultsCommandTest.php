<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\System\Clock;
use App\Domain\System\OperationalSetting;
use App\Domain\System\OperationalSettingRepository;
use App\Presentation\Console\PurgeDeliveryResultsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeDeliveryResultsCommandTest extends TestCase
{
    #[Test]
    public function itDeletesSentResultsOlderThanTheConfiguredRetention(): void
    {
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::once())
            ->method('deleteSentBefore')
            ->with(new DateTimeImmutable('2026-08-13 00:00:00+00:00'))
            ->willReturn(2);
        $tester = new CommandTester(new PurgeDeliveryResultsCommand($outbox, $this->settings(30), $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('2件', $tester->getDisplay());
    }

    #[Test]
    public function itDoesNotDeleteWithoutExecute(): void
    {
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::never())->method('deleteSentBefore');
        $tester = new CommandTester(new PurgeDeliveryResultsCommand($outbox, $this->settings(30), $this->clock()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('--execute', $tester->getDisplay());
    }

    private function settings(int $retentionDays): OperationalSettingRepository
    {
        $settings = $this->createStub(OperationalSettingRepository::class);
        $settings->method('findAll')->willReturn([
            new OperationalSetting('retention_delivery_results', $retentionDays, new DateTimeImmutable('2026-09-12 00:00:00+00:00'), 0),
        ]);

        return $settings;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-12 00:00:00+00:00'));

        return $clock;
    }
}
