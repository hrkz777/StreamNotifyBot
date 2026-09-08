<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Catalog\AgencyNotFound;
use App\Application\Catalog\RegisterStreamer;
use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Catalog\UnsupportedPlatform;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:register-streamer',
    description: '配信者と最初のプラットフォームアカウントを登録します。',
)]
final class RegisterStreamerCommand extends Command
{
    public function __construct(private readonly RegisterStreamer $registerStreamer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agency-id', InputArgument::REQUIRED, '所属区分ID（UUIDv7）')
            ->addArgument('platform', InputArgument::REQUIRED, 'プラットフォーム（youtube、twitch、twitcasting）')
            ->addArgument('registration-identifier', InputArgument::REQUIRED, 'チャンネルID、ハンドル、またはログイン名')
            ->addArgument('name', InputArgument::REQUIRED, '配信者名')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, '配信者名の言語コード（ja または en）', 'ja')
            ->addOption('color', null, InputOption::VALUE_REQUIRED, '表示色（#RRGGBB）')
            ->addOption('disabled', null, InputOption::VALUE_NONE, '配信者を無効状態で登録する');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $language = SupportedLanguage::fromInput(self::stringValue($input->getOption('language')));
            $platform = Platform::tryFrom(strtolower(trim(self::stringValue($input->getArgument('platform')))))
                ?? throw new InvalidArgumentException('対応していないプラットフォームです。');
            $result = $this->registerStreamer->register(new RegisterStreamerInput(
                self::stringValue($input->getArgument('agency-id')),
                $language,
                self::optionalString($input->getOption('color')),
                !(bool) $input->getOption('disabled'),
                [new StreamerName($language, self::stringValue($input->getArgument('name')))],
                $platform,
                self::stringValue($input->getArgument('registration-identifier')),
            ));
        } catch (AgencyNotFound|PlatformAccountNotFound|PlatformAccountResolutionFailed|UnsupportedPlatform|InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf('配信者を登録しました。配信者ID: %s、プラットフォームアカウントID: %s', $result->streamerId, $result->platformAccountId));

        return Command::SUCCESS;
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
