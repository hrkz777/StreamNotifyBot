<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;

final readonly class RegisterStreamerInput
{
    /** @var list<StreamerName> */
    public array $names;

    public string $registrationIdentifier;

    /** @param iterable<StreamerName> $names */
    public function __construct(
        public string $agencyId,
        public SupportedLanguage $defaultLanguage,
        public ?string $colorCode,
        public bool $isEnabled,
        iterable $names,
        public Platform $platform,
        string $registrationIdentifier,
    ) {
        if (!mb_check_encoding($registrationIdentifier, 'UTF-8')) {
            throw new InvalidArgumentException('登録識別子はUTF-8で指定してください。');
        }

        $normalizedIdentifier = preg_replace(
            '/^[\p{Z}\s]+|[\p{Z}\s]+$/u',
            '',
            $registrationIdentifier,
        ) ?? throw new InvalidArgumentException('登録識別子を正規化できませんでした。');
        if (
            $normalizedIdentifier === ''
            || mb_strlen($normalizedIdentifier) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $normalizedIdentifier) === 1
        ) {
            throw new InvalidArgumentException('登録識別子の形式が不正です。');
        }

        $this->names = array_values([...$names]);
        $this->registrationIdentifier = $normalizedIdentifier;
    }
}
