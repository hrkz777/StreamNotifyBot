<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Administration\IssueInitialSetupToken;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:administrator:issue-initial-setup-token',
    description: '初期owner作成用の一度限りのセットアップトークンを発行します。',
)]
final class IssueInitialSetupTokenCommand extends Command
{
    public function __construct(
        private readonly IssueInitialSetupToken $issueInitialSetupToken,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $issuedToken = $this->issueInitialSetupToken->issue();
        } catch (InitialSetupAlreadyCompleted $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $plainToken = $issuedToken->consumeToken();
        try {
            $setupUrl = $this->urlGenerator->generate('admin_initial_setup', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
            $io->warning('このトークンは初期設定画面で一度だけ使用できます。安全な経路で共有し、表示後は保存しないでください。');
            $io->writeln($setupUrl);
            $io->note(sprintf('有効期限: %s', $issuedToken->expiresAt->format(DATE_ATOM)));
        } finally {
            sodium_memzero($plainToken);
        }

        return Command::SUCCESS;
    }
}
