<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\AgencyCsvCodec;
use App\Application\Csv\CsvFormatException;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\SupportedLanguage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgencyCsvCodecTest extends TestCase
{
    #[Test]
    public function itExportsTheFixedSchemaAsShiftJisWithCrLf(): void
    {
        $csv = (new AgencyCsvCodec())->export([new Agency(
            '01990d4a-0000-7000-8000-000000000001',
            'independent',
            SupportedLanguage::Japanese,
            true,
            [
                new AgencyName(SupportedLanguage::Japanese, '個人勢'),
                new AgencyName(SupportedLanguage::English, 'Independent'),
            ],
        )]);

        $utf8Csv = mb_convert_encoding($csv, 'UTF-8', 'SJIS-win');

        self::assertFalse(str_starts_with($csv, "\xEF\xBB\xBF"));
        self::assertStringStartsWith("schema_version,code,default_language,is_independent,name_ja,short_name_ja,name_en,short_name_en\r\n", $utf8Csv);
        self::assertStringContainsString("1,independent,ja,1,個人勢,,Independent,\r\n", $utf8Csv);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $utf8Csv));
    }

    #[Test]
    public function itPreventsSpreadsheetFormulaExecution(): void
    {
        $csv = (new AgencyCsvCodec())->export([new Agency(
            '01990d4a-0000-7000-8000-000000000001',
            'independent',
            SupportedLanguage::Japanese,
            true,
            [
                new AgencyName(SupportedLanguage::Japanese, '=FORMULA'),
                new AgencyName(SupportedLanguage::English, '@formula'),
            ],
        )]);

        $utf8Csv = mb_convert_encoding($csv, 'UTF-8', 'SJIS-win');

        self::assertStringContainsString("'=FORMULA", $utf8Csv);
        self::assertStringContainsString("'@formula", $utf8Csv);
    }

    #[Test]
    public function itRejectsCharactersThatCannotBeRepresentedInShiftJis(): void
    {
        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('CSVの「name_ja」にShift_JISで表現できない文字「😀」（U+1F600）が含まれています。');

        (new AgencyCsvCodec())->export([new Agency(
            '01990d4a-0000-7000-8000-000000000001',
            'independent',
            SupportedLanguage::Japanese,
            true,
            [
                new AgencyName(SupportedLanguage::Japanese, '配信者😀'),
                new AgencyName(SupportedLanguage::English, 'Independent'),
            ],
        )]);
    }
}
