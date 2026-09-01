<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use InvalidArgumentException;
use LogicException;

final readonly class Streamer
{
    /** @var array<string, StreamerName> */
    private array $names;

    public ?string $colorCode;

    /**
     * @param iterable<StreamerName> $names
     */
    public function __construct(
        public string $id,
        public string $agencyId,
        public SupportedLanguage $defaultLanguage,
        ?string $colorCode,
        public bool $isEnabled,
        iterable $names,
    ) {
        self::assertUuidV7($id, '配信者ID');
        self::assertUuidV7($agencyId, '所属区分ID');

        if ($colorCode !== null && preg_match('/^#[0-9A-Fa-f]{6}$/D', $colorCode) !== 1) {
            throw new InvalidArgumentException('表示色は#RRGGBB形式で指定してください。');
        }

        $indexedNames = [];
        foreach ($names as $name) {
            if (isset($indexedNames[$name->language->value])) {
                throw new InvalidArgumentException('同じ言語の配信者名を複数登録できません。');
            }

            $indexedNames[$name->language->value] = $name;
        }

        if (!isset($indexedNames[$defaultLanguage->value])) {
            throw new InvalidArgumentException('既定言語の配信者名が必要です。');
        }

        $this->colorCode = $colorCode === null ? null : strtoupper($colorCode);
        $this->names = $indexedNames;
    }

    /** @return list<StreamerName> */
    public function names(): array
    {
        return array_values($this->names);
    }

    public function nameFor(SupportedLanguage $language): StreamerName
    {
        if (isset($this->names[$language->value])) {
            return $this->names[$language->value];
        }

        return $this->names[$this->defaultLanguage->value]
            ?? throw new LogicException('既定言語の配信者名がありません。');
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }
}
