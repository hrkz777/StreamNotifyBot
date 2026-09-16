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
            $lineNumber = 2;
            foreach ($rows as $row) {
                $escapedRow = [];
                foreach ($row as $columnIndex => $value) {
                    self::assertShiftJisRepresentable($value, $headers[$columnIndex] ?? sprintf('%d列目', $columnIndex + 1), $lineNumber);
                    $escapedRow[] = self::escapeSpreadsheetFormula($value);
                }
                fputcsv($stream, $escapedRow, escape: '');
                ++$lineNumber;
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

    private static function assertShiftJisRepresentable(string $value, string $columnName, int $lineNumber): void
    {
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $encoded = mb_convert_encoding($character, 'SJIS-win', 'UTF-8');
            if (mb_convert_encoding($encoded, 'UTF-8', 'SJIS-win') !== $character) {
                throw new CsvFormatException(sprintf(
                    'CSVの%d行目「%s」にShift_JISで表現できない文字「%s」が含まれています。',
                    $lineNumber,
                    $columnName,
                    $character,
                ));
            }
        }
    }
}
