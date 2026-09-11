<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Subscription\TwitchStreamSynchronizer;
use App\Application\Subscription\TwitCastingStreamSynchronizer;
use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use App\Presentation\Console\SyncPlatformStreamsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncPlatformStreamsCommandTest extends TestCase
{
    #[Test]
    public function itReportsBothPlatformCountsWhenSynchronizationSucceeds(): void
    {
        $twitch = $this->createMock(TwitchStreamSynchronizer::class);
        $twitch->expects(self::once())->method('sync')->willReturn(3);
        $twitCasting = $this->createMock(TwitCastingStreamSynchronizer::class);
        $twitCasting->expects(self::once())->method('sync')->willReturn(2);

        $tester = $this->tester(true, $twitch, $twitCasting);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Twitch: 3件 / TwitCasting: 2件', $tester->getDisplay());
    }

    #[Test]
    public function itContinuesWithTwitCastingWhenTwitchSynchronizationFails(): void
    {
        $twitch = $this->createMock(TwitchStreamSynchronizer::class);
        $twitch->expects(self::once())->method('sync')->willThrowException(new RuntimeException('Twitch APIに接続できません。'));
        $twitCasting = $this->createMock(TwitCastingStreamSynchronizer::class);
        $twitCasting->expects(self::once())->method('sync')->willReturn(2);

        $tester = $this->tester(true, $twitch, $twitCasting);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Twitch: Twitch APIに接続できません。', $tester->getDisplay());
    }

    #[Test]
    public function itDoesNotSynchronizeWhenTheJobIsDisabled(): void
    {
        $twitch = $this->createMock(TwitchStreamSynchronizer::class);
        $twitch->expects(self::never())->method('sync');
        $twitCasting = $this->createMock(TwitCastingStreamSynchronizer::class);
        $twitCasting->expects(self::never())->method('sync');

        $tester = $this->tester(false, $twitch, $twitCasting);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('配信状態同期ジョブは無効です。', $tester->getDisplay());
    }

    private function tester(bool $enabled, TwitchStreamSynchronizer $twitch, TwitCastingStreamSynchronizer $twitCasting): CommandTester
    {
        $policies = $this->createMock(JobPolicyRepository::class);
        $policies->expects(self::once())->method('get')->with(JobType::StreamPolling)->willReturn(new JobPolicy('01990d4a-0000-7000-8000-000000001100', JobType::StreamPolling, 20, 45, 8, 60, 3600, 2.0, 20, 120, $enabled, new DateTimeImmutable('2026-09-12T00:00:00Z'), 0));

        return new CommandTester(new SyncPlatformStreamsCommand($policies, $twitch, $twitCasting));
    }
}
