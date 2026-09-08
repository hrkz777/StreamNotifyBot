<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\System\Clock;
use DateInterval;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:security:purge-authentication-attempts', description: '保持期間を過ぎた認証試行を削除します。')]
final class PurgeAuthenticationAttemptsCommand extends Command
{
    public function __construct(
        private readonly AuthenticationAttemptRepository $authenticationAttemptRepository,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('retention-days', null, InputOption::VALUE_REQUIRED, '認証試行の保持日数（365日以上）', '365');
        $this->addOption('execute', null, InputOption::VALUE_NONE, '削除を実行します。指定しない場合は変更しません。');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $retentionDays = filter_var($input->getOption('retention-days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 365]]);
        if ($retentionDays === false) {
            throw new InvalidArgumentException('認証試行の保持日数は365日以上の整数で指定してください。');
        }

        $before = $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $retentionDays)));
        if (!$input->getOption('execute')) {
            $io->warning(sprintf('%sより前の認証試行は削除していません。実行する場合は --execute を指定してください。', $before->format('Y-m-d H:i:s T')));

            return Command::SUCCESS;
        }

        $count = $this->authenticationAttemptRepository->deleteBefore($before);
        $io->success(sprintf('%d件の期限切れ認証試行を削除しました。', $count));

        return Command::SUCCESS;
    }
}
