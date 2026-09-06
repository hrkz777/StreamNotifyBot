<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Security;

use App\Domain\Security\EncryptionKey;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionKeyTest extends TestCase
{
    #[Test]
    public function itRedactsDebugOutputAndRejectsSerialization(): void
    {
        $keyValue = str_repeat("\x01", 32);
        $key = new EncryptionKey('key-2026-01', $keyValue);

        $debugOutput = print_r($key, true);
        self::assertStringContainsString('[redacted]', $debugOutput);
        self::assertStringNotContainsString($keyValue, $debugOutput);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('暗号化鍵はシリアライズできません。');

        serialize($key);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeyProvider(): iterable
    {
        yield 'empty key id' => ['', str_repeat("\x01", 32)];
        yield 'unsafe key id' => ['key/id', str_repeat("\x01", 32)];
        yield 'short key value' => ['key-1', str_repeat("\x01", 31)];
        yield 'long key value' => ['key-1', str_repeat("\x01", 33)];
    }

    #[Test]
    #[DataProvider('invalidKeyProvider')]
    public function itRejectsAnInvalidKey(string $keyId, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EncryptionKey($keyId, $value);
    }
}
