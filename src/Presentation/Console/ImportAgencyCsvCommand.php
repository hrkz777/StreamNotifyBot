<?php
declare(strict_types=1);
namespace App\Presentation\Console;
use App\Application\Csv\ImportAgencyCsv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:catalog:import-agencies-csv',description:'所属区分CSVをプレビューまたは反映します。')]
final class ImportAgencyCsvCommand extends Command
{
 public function __construct(private readonly ImportAgencyCsv $importer){parent::__construct();}
 protected function configure():void{$this->addArgument('file',InputArgument::REQUIRED)->addOption('replace',null,InputOption::VALUE_NONE)->addOption('execute',null,InputOption::VALUE_NONE);}
 protected function execute(InputInterface $input,OutputInterface $output):int { $file=$input->getArgument('file'); if(!is_string($file)||!is_file($file)||($csv=file_get_contents($file))===false){$output->writeln('<error>CSVファイルを読み取れません。</error>');return Command::INVALID;} try{$rows=$this->importer->preview($csv);if(!(bool)$input->getOption('execute')){$output->writeln(sprintf('%d件をプレビューしました。反映するには --execute を指定してください。',count($rows)));return Command::SUCCESS;}$this->importer->execute($csv,(bool)$input->getOption('replace'));$output->writeln(sprintf('%d件を反映しました。',count($rows)));return Command::SUCCESS;}catch(\Throwable $e){$output->writeln('<error>'.$e->getMessage().'</error>');return Command::INVALID;}}
}
