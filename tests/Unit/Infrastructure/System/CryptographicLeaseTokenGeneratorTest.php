<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\System;

use App\Infrastructure\System\CryptographicLeaseTokenGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CryptographicLeaseTokenGeneratorTest extends TestCase
{
    #[Test]
    public function itGeneratesDistinct128BitLowercaseHexTokens(): void
    {
        $generator = new CryptographicLeaseTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $second);
        self::assertNotSame($first, $second);
    }
}
