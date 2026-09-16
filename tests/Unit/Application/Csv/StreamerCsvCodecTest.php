<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\StreamerCsvCodec;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamerCsvCodecTest extends TestCase
{
    #[Test]
    public function itExportsStreamersAndPlatformAccountsAsShiftJis(): void
    {
        $streamer = new Streamer('01990d4a-0000-7000-8000-000000000101', '01990d4a-0000-7000-8000-000000000001', SupportedLanguage::Japanese, '#123456', true, [new StreamerName(SupportedLanguage::Japanese, '=配信者')]);
        $account = new PlatformAccount('01990d4a-0000-7000-8000-000000000102', $streamer->id, Platform::YouTube, 'UCaaaaaaaaaaaaaaaaaaaaaa', '@streamer', null, null, null, null, null, true, new DateTimeImmutable('2026-09-14T00:00:00+00:00'));

        $contents = (new StreamerCsvCodec())->export([$streamer], [$account]);
        $csv = mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');

        self::assertStringStartsWith("streamer_id,agency_id,default_language,name_ja,name_en,color_code,is_enabled,platform_account_id,platform,external_id,registration_identifier,display_id,platform_name,platform_account_enabled\r\n", $csv);
        self::assertStringContainsString("'=配信者", $csv);
        self::assertStringContainsString(",youtube,UCaaaaaaaaaaaaaaaaaaaaaa,'@streamer,,,1\r\n", $csv);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $csv));
    }

    #[Test]
    public function itIdentifiesTheUnrepresentableCharacterAndColumn(): void
    {
        $streamer = new Streamer('01990d4a-0000-7000-8000-000000000101', '01990d4a-0000-7000-8000-000000000001', SupportedLanguage::Japanese, null, true, [new StreamerName(SupportedLanguage::Japanese, '配信者😀')]);

        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('CSVの2行目「name_ja」にShift_JISで表現できない文字「😀」が含まれています。');

        (new StreamerCsvCodec())->export([$streamer], []);
    }
}
