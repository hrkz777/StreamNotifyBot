<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Subscription\SyncTwitchStreams;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:twitch:sync-streams', description: 'Twitchのライブ配信状態を同期します。')]
final class SyncTwitchStreamsCommand extends Command
{
    public function __construct(private readonly JobPolicyRepository $jobPolicyRepository, private readonly SyncTwitchStreams $syncTwitchStreams)
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
        try {
            $savedCount = $this->syncTwitchStreams->sync();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('%d件のTwitch配信状態を同期しました。', $savedCount));

        return Command::SUCCESS;
    }
}
