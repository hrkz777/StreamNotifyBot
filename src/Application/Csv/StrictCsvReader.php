<?php

declare(strict_types=1);

namespace App\Application\Csv;

final readonly class StrictCsvReader
{
    public const int MAX_ROWS = 10000;
    public const int MAX_FIELD_BYTES = 16384;

    /**
     * @param list<string> $expectedHeaders
     * @return list<array<string, string>>
     */
    public function read(string $contents, array $expectedHeaders, int $schemaVersion): array
    {
        if (!mb_check_encoding($contents, 'UTF-8')) {
            throw new CsvFormatException('CSVはUTF-8で指定してください。');
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new CsvFormatException('CSVの読込先を初期化できませんでした。');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            $headers = fgetcsv($stream, escape: '');
            if ($headers === false || $headers !== $expectedHeaders) {
                throw new CsvFormatException('CSVヘッダーの名前または列順が正しくありません。');
            }

            $rows = [];
            $lineNumber = 1;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                ++$lineNumber;
                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== count($expectedHeaders)) {
                    throw new CsvFormatException(sprintf('%d行目の列数が正しくありません。', $lineNumber));
                }

                foreach ($values as $value) {
                    if (!is_string($value) || strlen($value) > self::MAX_FIELD_BYTES) {
                        throw new CsvFormatException(sprintf('%d行目に不正または長すぎる値があります。', $lineNumber));
                    }
                }

                $row = array_combine($expectedHeaders, $values);
                if (($row['schema_version'] ?? null) !== (string) $schemaVersion) {
                    throw new CsvFormatException(sprintf('%d行目のschema_versionが対応していません。', $lineNumber));
                }

                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw new CsvFormatException(sprintf('CSVは%d行以下にしてください。', self::MAX_ROWS));
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
