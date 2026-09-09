<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Stream\DeliverStreamNotifications;
use App\Application\Stream\DeliverStreamNotificationsInput;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stream-notifications:deliver', description: '未送信の配信通知をDiscordへ配送します。')]
final class DeliverStreamNotificationsCommand extends Command
{
    public function __construct(private readonly JobPolicyRepository $jobPolicyRepository, private readonly DeliverStreamNotifications $deliverStreamNotifications)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $policy = $this->jobPolicyRepository->get(JobType::Notification);
        if (!$policy->isEnabled) {
            $io->note('配信通知配送ジョブは無効です。');

            return Command::SUCCESS;
        }

        $result = $this->deliverStreamNotifications->deliver(new DeliverStreamNotificationsInput($policy->batchSize, $policy->leaseSeconds));
        $io->writeln(sprintf('取得: %d / 送信済み: %d / 抑止: %d / リース解放: %d / 競合破棄: %d', $result->claimedCount, $result->sentCount, $result->suppressedCount, $result->releasedCount, $result->staleResultCount));

        return Command::SUCCESS;
    }
}
