<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Domain\Administration\AdministratorTotpCredentialReencryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:security:rotate-administrator-totp-keys',
    description: '管理者TOTP秘密値を現行暗号化鍵で再暗号化します。',
)]
final class RotateAdministratorTotpKeysCommand extends Command
{
    public function __construct(private readonly AdministratorTotpCredentialReencryptor $reencryptor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, '再暗号化を実行します。指定しない場合は変更しません。');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('execute')) {
            $io->warning('再暗号化は実行していません。実行する場合は --execute を指定してください。');

            return Command::SUCCESS;
        }

        $count = $this->reencryptor->reencrypt();
        $io->success(sprintf('%d件の管理者TOTP資格情報を現行鍵で再暗号化しました。', $count));

        return Command::SUCCESS;
    }
}
