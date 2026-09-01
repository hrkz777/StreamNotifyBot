<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\System;

use App\Infrastructure\System\SymfonyUuidV7Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SymfonyUuidV7GeneratorTest extends TestCase
{
    #[Test]
    public function itGeneratesALowercaseRfc4122UuidV7(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            (new SymfonyUuidV7Generator())->generate(),
        );
    }
}
