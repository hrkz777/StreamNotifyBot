<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Catalog\AgencyAlreadyExists;
use App\Application\Catalog\CreateAgency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:create-agency',
    description: '所属区分を作成します。',
)]
final class CreateAgencyCommand extends Command
{
    public function __construct(private readonly CreateAgency $createAgency)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, '所属区分コード（英小文字、数字、ハイフン、アンダースコア）')
            ->addArgument('name', InputArgument::REQUIRED, '所属区分名')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, '名称の言語コード（ja または en）', 'ja')
            ->addOption('short-name', null, InputOption::VALUE_REQUIRED, '短縮名称')
            ->addOption('independent', null, InputOption::VALUE_NONE, '個人勢の所属区分として作成する');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $language = SupportedLanguage::fromInput(self::stringValue($input->getOption('language')));
            $agency = $this->createAgency->create(
                self::stringValue($input->getArgument('code')),
                $language,
                (bool) $input->getOption('independent'),
                [new AgencyName(
                    $language,
                    self::stringValue($input->getArgument('name')),
                    self::optionalString($input->getOption('short-name')),
                )],
            );
        } catch (AgencyAlreadyExists|InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf('所属区分「%s」（%s）を作成しました。', $agency->nameFor($language)->displayName(), $agency->code));

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
