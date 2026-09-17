<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Domain\Catalog\Agency;
use App\Domain\Catalog\SupportedLanguage;

final readonly class AgencyCsvCodec
{
    public const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const array HEADERS = [
        'schema_version',
        'code',
        'default_language',
        'is_independent',
        'name_ja',
        'short_name_ja',
        'name_en',
        'short_name_en',
    ];

    /** @param iterable<Agency> $agencies */
    public function export(iterable $agencies, CsvExportEncoding $encoding = CsvExportEncoding::ShiftJis): string
    {
        $rows = [];
        foreach ($agencies as $agency) {
            $rows[] = [
                (string) self::SCHEMA_VERSION,
                $agency->code,
                $agency->defaultLanguage->value,
                $agency->isIndependent ? '1' : '0',
                $agency->nameFor(SupportedLanguage::Japanese)->name,
                $agency->nameFor(SupportedLanguage::Japanese)->shortName ?? '',
                $agency->nameFor(SupportedLanguage::English)->name,
                $agency->nameFor(SupportedLanguage::English)->shortName ?? '',
            ];
        }

        return CsvEncoder::encode(self::HEADERS, $rows, encoding: $encoding);
    }
}
