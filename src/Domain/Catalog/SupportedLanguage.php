<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

enum SupportedLanguage: string
{
    case Japanese = 'ja';
    case English = 'en';

    public static function fromInput(string $languageCode): self
    {
        $normalizedLanguageCode = strtolower(trim($languageCode));

        return self::tryFrom($normalizedLanguageCode)
            ?? throw new InvalidArgumentException('対応していない言語コードです。');
    }
}
