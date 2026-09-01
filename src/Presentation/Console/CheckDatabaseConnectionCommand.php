<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\System\CheckDatabaseConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:check',
    description: 'MariaDBへの接続と最小クエリを確認します。',
)]
final class CheckDatabaseConnectionCommand extends Command
{
    public function __construct(private readonly CheckDatabaseConnection $checkDatabaseConnection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->checkDatabaseConnection->isHealthy()) {
            $io->error('データベースへ接続できませんでした。設定とMariaDBの稼働状態を確認してください。');

            return Command::FAILURE;
        }

        $io->success('データベースへ接続できました。');

        return Command::SUCCESS;
    }
}
