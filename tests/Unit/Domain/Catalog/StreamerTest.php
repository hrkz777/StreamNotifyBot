<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamerTest extends TestCase
{
    #[Test]
    public function itNormalizesColorAndFallsBackToTheDefaultName(): void
    {
        $streamer = new Streamer(
            '01990d4a-0000-7000-8000-000000000101',
            '01990d4a-0000-7000-8000-000000000001',
            SupportedLanguage::Japanese,
            '#a1b2c3',
            true,
            [new StreamerName(SupportedLanguage::Japanese, '　配信者名　')],
        );

        self::assertSame('#A1B2C3', $streamer->colorCode);
        self::assertSame('配信者名', $streamer->nameFor(SupportedLanguage::English)->name);
    }

    #[Test]
    public function itRejectsAnInvalidColor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Streamer(
            '01990d4a-0000-7000-8000-000000000102',
            '01990d4a-0000-7000-8000-000000000001',
            SupportedLanguage::Japanese,
            'red',
            true,
            [new StreamerName(SupportedLanguage::Japanese, '配信者名')],
        );
    }
}
