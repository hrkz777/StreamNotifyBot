<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;
use LogicException;

final readonly class Agency
{
    /** @var array<string, AgencyName> */
    private array $names;

    /**
     * @param iterable<AgencyName> $names
     */
    public function __construct(
        public string $id,
        public string $code,
        public SupportedLanguage $defaultLanguage,
        public bool $isIndependent,
        iterable $names,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('IDは小文字標準形式のUUIDv7で指定してください。');
        }

        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('所属区分コードの形式が不正です。');
        }

        $indexedNames = [];
        foreach ($names as $name) {
            if (isset($indexedNames[$name->language->value])) {
                throw new InvalidArgumentException('同じ言語の名称を複数登録できません。');
            }

            $indexedNames[$name->language->value] = $name;
        }

        if (!isset($indexedNames[$defaultLanguage->value])) {
            throw new InvalidArgumentException('既定言語の名称が必要です。');
        }

        $this->names = $indexedNames;
    }

    /** @return list<AgencyName> */
    public function names(): array
    {
        return array_values($this->names);
    }

    public function nameFor(SupportedLanguage $language): AgencyName
    {
        if (isset($this->names[$language->value])) {
            return $this->names[$language->value];
        }

        return $this->names[$this->defaultLanguage->value]
            ?? throw new LogicException('既定言語の名称がありません。');
    }
}
