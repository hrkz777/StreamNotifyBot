<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Security\SecretConfigurationInvalid;
use App\Infrastructure\Security\JsonFileEncryptionKeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonFileEncryptionKeyRingTest extends TestCase
{
    private string $keyRingFilePath;

    protected function setUp(): void
    {
        $keyRingFilePath = tempnam(sys_get_temp_dir(), 'stream-notify-key-ring-');
        if (!is_string($keyRingFilePath)) {
            throw new RuntimeException('テスト用一時ファイルを作成できません。');
        }

        $this->keyRingFilePath = $keyRingFilePath;
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyRingFilePath)) {
            unlink($this->keyRingFilePath);
        }
    }

    #[Test]
    public function itLoadsTheCurrentAndPreviousKeysFromAFile(): void
    {
        $this->writeKeyRing(json_encode([
            'current_key_id' => 'key-current',
            'keys' => [
                ['id' => 'key-previous', 'value' => base64_encode(str_repeat("\x01", 32))],
                ['id' => 'key-current', 'value' => base64_encode(str_repeat("\x02", 32))],
            ],
        ], JSON_THROW_ON_ERROR));

        $keyRing = new JsonFileEncryptionKeyRing($this->keyRingFilePath);

        self::assertSame('key-current', $keyRing->current()->id);
        self::assertSame(str_repeat("\x02", 32), $keyRing->current()->value());
        self::assertSame(str_repeat("\x01", 32), $keyRing->find('key-previous')?->value());
        self::assertNull($keyRing->find('key-missing'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeyRingProvider(): iterable
    {
        $encodedKey = base64_encode(str_repeat("\x01", 32));

        yield 'invalid json' => ['{'];
        yield 'unknown root field' => [sprintf(
            '{"current_key_id":"key-1","keys":[{"id":"key-1","value":"%s"}],"unknown":true}',
            $encodedKey,
        )];
        yield 'missing current key' => [sprintf(
            '{"current_key_id":"key-2","keys":[{"id":"key-1","value":"%s"}]}',
            $encodedKey,
        )];
        yield 'duplicate key id' => [sprintf(
            '{"current_key_id":"key-1","keys":[{"id":"key-1","value":"%1$s"},{"id":"key-1","value":"%1$s"}]}',
            $encodedKey,
        )];
        yield 'non-canonical base64' => [
            '{"current_key_id":"key-1","keys":[{"id":"key-1","value":"not base64"}]}',
        ];
        yield 'wrong key length' => [sprintf(
            '{"current_key_id":"key-1","keys":[{"id":"key-1","value":"%s"}]}',
            base64_encode(str_repeat("\x01", 31)),
        )];
    }

    #[Test]
    #[DataProvider('invalidKeyRingProvider')]
    public function itRejectsAnInvalidKeyRingWithoutExposingDetails(string $contents): void
    {
        $this->writeKeyRing($contents);
        $keyRing = new JsonFileEncryptionKeyRing($this->keyRingFilePath);

        try {
            $keyRing->current();
            self::fail('SecretConfigurationInvalid was not thrown.');
        } catch (SecretConfigurationInvalid $exception) {
            self::assertSame('秘密情報の暗号化設定が不正です。', $exception->getMessage());
            self::assertStringNotContainsString($this->keyRingFilePath, $exception->getMessage());
            self::assertStringNotContainsString($contents, $exception->getMessage());
        }
    }

    #[Test]
    public function itRejectsAMissingKeyRingFile(): void
    {
        unlink($this->keyRingFilePath);
        $keyRing = new JsonFileEncryptionKeyRing($this->keyRingFilePath);

        $this->expectException(SecretConfigurationInvalid::class);

        $keyRing->current();
    }

    private function writeKeyRing(string $contents): void
    {
        if (file_put_contents($this->keyRingFilePath, $contents) === false) {
            throw new RuntimeException('テスト用鍵リングを書き込めません。');
        }
    }
}
