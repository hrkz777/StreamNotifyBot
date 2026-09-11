<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Subscription\SyncYouTubeChannelFeed;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsUnavailable;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:youtube:sync-channel-feed', description: 'YouTubeチャンネルのAtomフィードから動画状態を同期します。')]
final class SyncYouTubeChannelFeedCommand extends Command
{
    public function __construct(private readonly SyncYouTubeChannelFeed $syncYouTubeChannelFeed)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('platform-account-id', InputArgument::REQUIRED, 'YouTubeプラットフォームアカウントID（UUIDv7）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('platform-account-id');
        if (!is_string($accountId)) {
            $io->error('YouTubeプラットフォームアカウントIDを指定してください。');

            return Command::INVALID;
        }

        try {
            $savedCount = $this->syncYouTubeChannelFeed->sync($accountId);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (YouTubeVideoDetailsUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%d件のYouTube動画状態を同期しました。', $savedCount));

        return Command::SUCCESS;
    }
}
