<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Security;

use App\Domain\Security\EncryptedSecret;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptedSecretTest extends TestCase
{
    #[Test]
    public function itAcceptsTheSupportedEnvelopeShape(): void
    {
        $secret = new EncryptedSecret(str_repeat('c', 16), str_repeat('n', 24), 'key-2026-01');

        self::assertSame(EncryptedSecret::FORMAT_VERSION, $secret->formatVersion);
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'short ciphertext' => [str_repeat('c', 15), str_repeat('n', 24), 'key-1', 1];
        yield 'short nonce' => [str_repeat('c', 16), str_repeat('n', 23), 'key-1', 1];
        yield 'invalid key id' => [str_repeat('c', 16), str_repeat('n', 24), 'key/id', 1];
        yield 'unsupported version' => [str_repeat('c', 16), str_repeat('n', 24), 'key-1', 2];
    }

    #[Test]
    #[DataProvider('invalidEnvelopeProvider')]
    public function itRejectsAnInvalidEnvelope(
        string $encryptedValue,
        string $nonce,
        string $keyId,
        int $formatVersion,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new EncryptedSecret($encryptedValue, $nonce, $keyId, $formatVersion);
    }
}
