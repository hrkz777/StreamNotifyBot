<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\ImportStreamerCsv;
use App\Application\Csv\StrictCsvReader;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\SupportedLanguage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportStreamerCsvTest extends TestCase
{
    private const string AGENCY_ID = '01990d4a-0000-7000-8000-000000000401';

    #[Test]
    public function itGroupsPlatformAccountsForTheSameStreamer(): void
    {
        $registrations = $this->importer()->preview(<<<'CSV'
            schema_version,streamer_key,agency_code,default_language,name_ja,name_en,color_code,is_enabled,platform,registration_identifier,platform_account_enabled
            1,streamer-a,example,ja,配信者A,Streamer A,#123456,1,youtube,@streamer-a,1
            1,streamer-a,example,ja,配信者A,Streamer A,#123456,1,twitch,streamer-a,0
            CSV);

        self::assertCount(1, $registrations);
        self::assertSame(self::AGENCY_ID, $registrations[0]->agencyId);
        self::assertSame('配信者A', $registrations[0]->names[0]->name);
        self::assertCount(2, $registrations[0]->accounts);
        self::assertFalse($registrations[0]->accounts[1]->platformAccountEnabled);
    }

    #[Test]
    public function itRejectsDuplicatePlatformAccounts(): void
    {
        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('同じプラットフォームアカウントを複数行に指定できません。');

        $this->importer()->preview(<<<'CSV'
            schema_version,streamer_key,agency_code,default_language,name_ja,name_en,color_code,is_enabled,platform,registration_identifier,platform_account_enabled
            1,streamer-a,example,ja,配信者A,,#123456,1,youtube,@streamer-a,1
            1,streamer-b,example,ja,配信者B,,#654321,1,youtube,@streamer-a,1
            CSV);
    }

    #[Test]
    public function itRejectsInconsistentStreamerDataInTheSameGroup(): void
    {
        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('同じstreamer_keyでは配信者情報を一致させてください。');

        $this->importer()->preview(<<<'CSV'
            schema_version,streamer_key,agency_code,default_language,name_ja,name_en,color_code,is_enabled,platform,registration_identifier,platform_account_enabled
            1,streamer-a,example,ja,配信者A,,#123456,1,youtube,@streamer-a,1
            1,streamer-a,example,ja,配信者B,,#123456,1,twitch,streamer-a,1
            CSV);
    }

    private function importer(): ImportStreamerCsv
    {
        $agencies = $this->createStub(AgencyRepository::class);
        $agencies->method('findByCode')->willReturn(new Agency(
            self::AGENCY_ID,
            'example',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::Japanese, '所属区分')],
        ));

        return new ImportStreamerCsv(new StrictCsvReader(), $agencies);
    }
}
