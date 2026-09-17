<?php

declare(strict_types=1);

namespace App\Application\Csv;

enum CsvExportEncoding: string
{
    case Utf8 = 'utf8';
    case ShiftJis = 'shift_jis';

    public static function fromRequestValue(string $value): self
    {
        return self::tryFrom($value) ?? throw new CsvFormatException('CSVの文字コードはUTF-8またはShift_JISを指定してください。');
    }

    public function charset(): string
    {
        return match ($this) {
            self::Utf8 => 'UTF-8',
            self::ShiftJis => 'Shift_JIS',
        };
    }

    public function encode(string $contents): string
    {
        if ($this === self::Utf8) {
            return "\xEF\xBB\xBF{$contents}";
        }

        $shiftJisContents = mb_convert_encoding($contents, 'SJIS-win', 'UTF-8');
        if (mb_convert_encoding($shiftJisContents, 'UTF-8', 'SJIS-win') !== $contents) {
            throw new CsvFormatException('CSVにShift_JISで表現できない文字が含まれています。UTF-8を選択してください。');
        }

        return $shiftJisContents;
    }
}
