<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;

final readonly class StreamerCsvRegistration
{
    /**
     * @param list<StreamerName> $names
     * @param list<RegisterStreamerInput> $accounts
     */
    public function __construct(
        public string $key,
        public string $agencyId,
        public SupportedLanguage $defaultLanguage,
        public ?string $colorCode,
        public bool $isEnabled,
        public array $names,
        public array $accounts,
    ) {
    }
}
