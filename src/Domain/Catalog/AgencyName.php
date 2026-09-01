<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class AgencyName
{
    public string $name;

    public ?string $shortName;

    public function __construct(
        public SupportedLanguage $language,
        string $name,
        ?string $shortName = null,
    ) {
        $this->name = self::normalizeRequiredName($name);
        $this->shortName = self::normalizeOptionalName($shortName);
    }

    public function displayName(): string
    {
        return $this->shortName ?? $this->name;
    }

    private static function normalizeRequiredName(string $name): string
    {
        $normalizedName = self::trimWhitespace($name);
        self::assertValidName($normalizedName);

        return $normalizedName;
    }

    private static function normalizeOptionalName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $normalizedName = self::trimWhitespace($name);
        if ($normalizedName === '') {
            return null;
        }

        self::assertValidName($normalizedName);

        return $normalizedName;
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '' || mb_strlen($name) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('名称は191文字以内で、改行や制御文字を含まない値にしてください。');
        }
    }

    private static function trimWhitespace(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidArgumentException('名称はUTF-8で指定してください。');
        }

        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $name)
            ?? throw new InvalidArgumentException('名称を正規化できませんでした。');
    }
}
