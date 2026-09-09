<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Subscription\ProcessWebhookEvents;
use App\Application\Subscription\ProcessWebhookEventsInput;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:webhook-events:process', description: '受信済みWebhookイベントを処理します。')]
final class ProcessWebhookEventsCommand extends Command
{
    public function __construct(private readonly JobPolicyRepository $jobPolicyRepository, private readonly ProcessWebhookEvents $processWebhookEvents)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $policy = $this->jobPolicyRepository->get(JobType::WebhookEvent);
        if (!$policy->isEnabled) {
            $io->note('Webhook後続処理ジョブは無効です。');

            return Command::SUCCESS;
        }

        $result = $this->processWebhookEvents->process(new ProcessWebhookEventsInput($policy->batchSize, $policy->maxRuntimeSeconds, $policy->leaseSeconds));
        $io->writeln(sprintf('取得: %d / 有効: %d / 破棄: %d / 未処理解放: %d / 競合破棄: %d', $result->claimedCount, $result->processedCount, $result->discardedCount, $result->releasedCount, $result->staleResultCount));

        return Command::SUCCESS;
    }
}
