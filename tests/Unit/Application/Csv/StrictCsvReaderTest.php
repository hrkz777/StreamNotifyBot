<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\CsvFormatException;
use App\Application\Csv\StrictCsvReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictCsvReaderTest extends TestCase
{
    private const array HEADERS = ['schema_version', 'code', 'name'];

    #[Test]
    public function itReadsUtf8CsvWithAnOptionalBom(): void
    {
        $rows = (new StrictCsvReader())->read(
            "\xEF\xBB\xBFschema_version,code,name\r\n1,independent,個人勢\r\n",
            self::HEADERS,
            1,
        );

        self::assertSame([['schema_version' => '1', 'code' => 'independent', 'name' => '個人勢']], $rows);
    }

    #[Test]
    public function itRejectsHeadersThatDoNotExactlyMatchTheSchema(): void
    {
        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('CSVヘッダーの名前または列順が正しくありません。');

        (new StrictCsvReader())->read("code,schema_version,name\nindependent,1,個人勢\n", self::HEADERS, 1);
    }

    #[Test]
    public function itRejectsMixedOrUnsupportedSchemaVersions(): void
    {
        $this->expectException(CsvFormatException::class);
        $this->expectExceptionMessage('3行目のschema_versionが対応していません。');

        (new StrictCsvReader())->read("schema_version,code,name\n1,independent,個人勢\n2,other,Other\n", self::HEADERS, 1);
    }
}
