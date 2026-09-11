<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Subscription\TwitchStreamSynchronizer;
use App\Application\Subscription\TwitCastingStreamSynchronizer;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:streams:sync', description: '対応済みプラットフォームのライブ配信状態を同期します。')]
final class SyncPlatformStreamsCommand extends Command
{
    public function __construct(private readonly JobPolicyRepository $jobPolicyRepository, private readonly TwitchStreamSynchronizer $twitchStreamSynchronizer, private readonly TwitCastingStreamSynchronizer $twitCastingStreamSynchronizer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->jobPolicyRepository->get(JobType::StreamPolling)->isEnabled) {
            $io->note('配信状態同期ジョブは無効です。');

            return Command::SUCCESS;
        }
        $failures = [];
        $twitch = $this->sync($this->twitchStreamSynchronizer, 'Twitch', $failures);
        $twitCasting = $this->sync($this->twitCastingStreamSynchronizer, 'TwitCasting', $failures);
        if ($failures !== []) {
            foreach ($failures as $failure) {
                $io->error($failure);
            }

            return Command::FAILURE;
        }
        $io->success(sprintf('Twitch: %d件 / TwitCasting: %d件の配信状態を同期しました。', $twitch, $twitCasting));

        return Command::SUCCESS;
    }

    /** @param list<string> $failures */
    private function sync(TwitchStreamSynchronizer|TwitCastingStreamSynchronizer $synchronizer, string $platform, array &$failures): int
    {
        try {
            return $synchronizer->sync();
        } catch (\RuntimeException $exception) {
            $failures[] = sprintf('%s: %s', $platform, $exception->getMessage());

            return 0;
        }
    }
}
