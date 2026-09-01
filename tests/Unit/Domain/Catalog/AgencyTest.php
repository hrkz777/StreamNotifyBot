<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgencyTest extends TestCase
{
    #[Test]
    public function itNormalizesNamesAndFallsBackToTheDefaultLanguage(): void
    {
        $agency = new Agency(
            '01990d4a-0000-7000-8000-000000000010',
            'example_agency',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::Japanese, '　テスト事務所　', '　')],
        );

        self::assertSame('テスト事務所', $agency->nameFor(SupportedLanguage::English)->name);
        self::assertSame('テスト事務所', $agency->nameFor(SupportedLanguage::Japanese)->displayName());
    }

    #[Test]
    public function itRequiresANameForTheDefaultLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Agency(
            '01990d4a-0000-7000-8000-000000000011',
            'example_agency',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::English, 'Example Agency')],
        );
    }

    #[Test]
    public function itRejectsControlCharactersInNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AgencyName(SupportedLanguage::Japanese, "テスト\n事務所");
    }
}
