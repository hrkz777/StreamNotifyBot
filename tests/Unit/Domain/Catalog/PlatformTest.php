<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Platform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    #[Test]
    public function itKeepsStableCodesSeparateFromDisplayIdentifiers(): void
    {
        self::assertSame('youtube', Platform::YouTube->value);
        self::assertSame('YouTube', Platform::YouTube->displayId());
        self::assertSame('twitch', Platform::Twitch->value);
        self::assertSame('Twitch', Platform::Twitch->displayId());
        self::assertSame('twitcasting', Platform::TwitCasting->value);
        self::assertSame('TwitCasting', Platform::TwitCasting->displayId());
    }
}
