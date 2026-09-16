<?php

declare(strict_types=1);

namespace App\Application\Csv;

final class CsvEncoder
{
    /**
     * @param list<string> $headers
     * @param iterable<int, list<string>> $rows
     * @param list<string>|null $recordLabels
     */
    public static function encode(array $headers, iterable $rows, ?array $recordLabels = null): string
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
                    self::assertShiftJisRepresentable(
                        $value,
                        $headers[$columnIndex] ?? sprintf('%d列目', $columnIndex + 1),
                        $recordLabels[$lineNumber - 2] ?? null,
                    );
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

    private static function assertShiftJisRepresentable(string $value, string $columnName, ?string $recordLabel): void
    {
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $encoded = mb_convert_encoding($character, 'SJIS-win', 'UTF-8');
            if (mb_convert_encoding($encoded, 'UTF-8', 'SJIS-win') !== $character) {
                $prefix = $recordLabel === null ? 'CSV' : $recordLabel;

                throw new CsvFormatException(sprintf(
                    '%sの「%s」にShift_JISで表現できない文字%sが含まれています。',
                    $prefix,
                    $columnName,
                    self::describeCharacter($character),
                ));
            }
        }
    }

    private static function describeCharacter(string $character): string
    {
        $codePoint = mb_ord($character, 'UTF-8');
        $codePointLabel = sprintf('U+%04X', $codePoint);

        if (preg_match('/^[\p{C}\p{M}]$/u', $character) === 1) {
            return sprintf(' %s（表示されない文字）', $codePointLabel);
        }

        return sprintf('「%s」（%s）', $character, $codePointLabel);
    }
}
