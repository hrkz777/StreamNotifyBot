<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class StreamerName
{
    public string $name;

    public function __construct(
        public SupportedLanguage $language,
        string $name,
    ) {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidArgumentException('配信者名はUTF-8で指定してください。');
        }

        $normalizedName = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $name)
            ?? throw new InvalidArgumentException('配信者名を正規化できませんでした。');

        if (
            $normalizedName === ''
            || mb_strlen($normalizedName) > 191
            || preg_match('/[\x00-\x1F\x7F]/u', $normalizedName) === 1
        ) {
            throw new InvalidArgumentException('配信者名は191文字以内で、改行や制御文字を含まない値にしてください。');
        }

        $this->name = $normalizedName;
    }
}
