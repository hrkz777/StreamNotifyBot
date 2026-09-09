<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Domain\Administration\AuditLogRepository;
use App\Domain\System\Clock;
use DateInterval;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:security:purge-audit-logs', description: '保持期間を過ぎた監査ログを削除します。')]
final class PurgeAuditLogsCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('retention-days', null, InputOption::VALUE_REQUIRED, '監査ログの保持日数（365日以上）', '365');
        $this->addOption('execute', null, InputOption::VALUE_NONE, '削除を実行します。指定しない場合は変更しません。');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $retentionDays = filter_var($input->getOption('retention-days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 365]]);
        if ($retentionDays === false) {
            throw new InvalidArgumentException('監査ログの保持日数は365日以上の整数で指定してください。');
        }

        $before = $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $retentionDays)));
        if (!$input->getOption('execute')) {
            $io->warning(sprintf('%sより前の監査ログは削除していません。実行する場合は --execute を指定してください。', $before->format('Y-m-d H:i:s T')));

            return Command::SUCCESS;
        }

        $count = $this->auditLogRepository->deleteBefore($before);
        $io->success(sprintf('%d件の期限切れ監査ログを削除しました。', $count));

        return Command::SUCCESS;
    }
}
