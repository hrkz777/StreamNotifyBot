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
    public function export(iterable $agencies): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new CsvFormatException('CSVの出力先を初期化できませんでした。');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, self::HEADERS, escape: '');
            foreach ($agencies as $agency) {
                fputcsv($stream, [
                    (string) self::SCHEMA_VERSION,
                    self::escapeSpreadsheetFormula($agency->code),
                    $agency->defaultLanguage->value,
                    $agency->isIndependent ? '1' : '0',
                    self::escapeSpreadsheetFormula($agency->nameFor(SupportedLanguage::Japanese)->name),
                    self::escapeNullableSpreadsheetFormula($agency->nameFor(SupportedLanguage::Japanese)->shortName),
                    self::escapeSpreadsheetFormula($agency->nameFor(SupportedLanguage::English)->name),
                    self::escapeNullableSpreadsheetFormula($agency->nameFor(SupportedLanguage::English)->shortName),
                ], escape: '');
            }

            rewind($stream);

            return str_replace("\n", "\r\n", stream_get_contents($stream) ?: '');
        } finally {
            fclose($stream);
        }
    }

    private static function escapeNullableSpreadsheetFormula(?string $value): string
    {
        return $value === null ? '' : self::escapeSpreadsheetFormula($value);
    }

    private static function escapeSpreadsheetFormula(string $value): string
    {
        return preg_match('/^[=+\-@]/D', $value) === 1 ? "'{$value}" : $value;
    }
}
