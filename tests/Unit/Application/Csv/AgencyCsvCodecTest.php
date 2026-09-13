<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\AgencyCsvCodec;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\SupportedLanguage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgencyCsvCodecTest extends TestCase
{
    #[Test]
    public function itExportsTheFixedSchemaWithBomAndCrLf(): void
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

        self::assertStringStartsWith("\xEF\xBB\xBFschema_version,code,default_language,is_independent,name_ja,short_name_ja,name_en,short_name_en\r\n", $csv);
        self::assertStringContainsString("1,independent,ja,1,個人勢,,Independent,\r\n", $csv);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $csv));
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

        self::assertStringContainsString("'=FORMULA", $csv);
        self::assertStringContainsString("'@formula", $csv);
    }
}
