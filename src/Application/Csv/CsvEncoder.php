<?php

declare(strict_types=1);

namespace App\Application\Csv;

final class CsvEncoder
{
    /**
     * @param list<string> $headers
     * @param iterable<int, list<string>> $rows
     */
    public static function encode(array $headers, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new CsvFormatException('CSVの出力先を初期化できませんでした。');
        }

        try {
            fputcsv($stream, $headers, escape: '');
            foreach ($rows as $row) {
                $escapedRow = [];
                foreach ($row as $value) {
                    $escapedRow[] = self::escapeSpreadsheetFormula($value);
                }
                fputcsv($stream, $escapedRow, escape: '');
            }
            rewind($stream);
            $contents = str_replace("\n", "\r\n", stream_get_contents($stream) ?: '');
            $shiftJisContents = mb_convert_encoding($contents, 'SJIS-win', 'UTF-8');
            if (mb_convert_encoding($shiftJisContents, 'UTF-8', 'SJIS-win') !== $contents) {
                throw new CsvFormatException('CSVにShift_JISで表現できない文字が含まれています。');
            }

            return $shiftJisContents;
        } finally {
            fclose($stream);
        }
    }

    private static function escapeSpreadsheetFormula(string $value): string
    {
        return preg_match('/^[=+\-@]/D', $value) === 1 ? "'{$value}" : $value;
    }
}
