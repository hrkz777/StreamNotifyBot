<?php
declare(strict_types=1);
namespace App\Application\Csv;
use App\Domain\Catalog\{Agency,AgencyName,AgencyRepository,SupportedLanguage};
use App\Domain\System\IdGenerator;
final readonly class ImportAgencyCsv
{
    public function __construct(private StrictCsvReader $reader, private AgencyRepository $agencies, private IdGenerator $ids) {}
    /** @return list<Agency> */
    public function preview(string $csv): array
    {
        $result=[];
        foreach ($this->reader->read($csv, AgencyCsvCodec::HEADERS, AgencyCsvCodec::SCHEMA_VERSION) as $row) {
            $existing=$this->agencies->findByCode($row['code']);
            if (!in_array($row['is_independent'], ['0','1'], true)) throw new CsvFormatException('is_independentは0または1で指定してください。');
            $result[]=new Agency($existing === null ? $this->ids->generate() : $existing->id, $row['code'], SupportedLanguage::fromInput($row['default_language']), $row['is_independent']==='1', [new AgencyName(SupportedLanguage::Japanese, $row['name_ja'], $row['short_name_ja'] ?: null),new AgencyName(SupportedLanguage::English, $row['name_en'], $row['short_name_en'] ?: null)]);
        }
        return $result;
    }
    public function execute(string $csv, bool $replace): void { $this->agencies->replaceFromCsv($this->preview($csv), $replace); }
}
