<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Subscription\RenewWebhookSubscriptions;
use App\Application\Subscription\RenewWebhookSubscriptionsInput;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:webhook-subscriptions:renew',
    description: '期限に到達したWebhook購読を更新します。',
)]
final class RenewWebhookSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly JobPolicyRepository $jobPolicyRepository,
        private readonly RenewWebhookSubscriptions $renewWebhookSubscriptions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $policy = $this->jobPolicyRepository->get(JobType::SubscriptionRenewal);

        if (!$policy->isEnabled) {
            $io->note('Webhook購読更新ジョブは無効です。');

            return Command::SUCCESS;
        }

        $result = $this->renewWebhookSubscriptions->renew(new RenewWebhookSubscriptionsInput(
            batchSize: $policy->batchSize,
            maxRuntimeSeconds: $policy->maxRuntimeSeconds,
            maxAttempts: $policy->maxAttempts,
            retryInitialDelaySeconds: $policy->retryInitialDelaySeconds,
            retryMaxDelaySeconds: $policy->retryMaxDelaySeconds,
            backoffMultiplier: $policy->backoffMultiplier,
            jitterPercent: $policy->jitterPercent,
            leaseSeconds: $policy->leaseSeconds,
        ));

        $io->writeln(sprintf(
            '取得: %d / 受付: %d / 再試行: %d / 恒久失敗: %d / 未処理解放: %d / 競合破棄: %d',
            $result->claimedCount,
            $result->acceptedCount,
            $result->retryScheduledCount,
            $result->permanentlyFailedCount,
            $result->releasedCount,
            $result->staleResultCount,
        ));

        return Command::SUCCESS;
    }
}
