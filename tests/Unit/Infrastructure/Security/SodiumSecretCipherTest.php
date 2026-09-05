<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\EncryptionKey;
use App\Domain\Security\EncryptionKeyRing;
use App\Domain\Security\SecretDecryptionFailed;
use App\Domain\Security\SecretPurpose;
use App\Infrastructure\Security\SodiumSecretCipher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SodiumSecretCipherTest extends TestCase
{
    private const string RECORD_ID = '01991e40-9c00-7000-8000-000000000001';

    #[Test]
    public function itEncryptsAndDecryptsWithAUniqueNonce(): void
    {
        $key = new EncryptionKey('key-current', str_repeat("\x01", 32));
        $keyRing = $this->createStub(EncryptionKeyRing::class);
        $keyRing->method('current')->willReturn($key);
        $keyRing->method('find')->willReturnMap([['key-current', $key]]);
        $cipher = new SodiumSecretCipher($keyRing);

        $first = $cipher->encrypt('sensitive value', SecretPurpose::AdministratorTotpSecret, self::RECORD_ID);
        $second = $cipher->encrypt('sensitive value', SecretPurpose::AdministratorTotpSecret, self::RECORD_ID);

        self::assertNotSame($first->nonce, $second->nonce);
        self::assertNotSame($first->encryptedValue, $second->encryptedValue);
        self::assertSame('sensitive value', $cipher->decrypt(
            $first,
            SecretPurpose::AdministratorTotpSecret,
            self::RECORD_ID,
        ));
    }

    #[Test]
    public function itRejectsTamperingAndAdditionalDataMismatchesWithoutExposingTheValue(): void
    {
        $key = new EncryptionKey('key-current', str_repeat("\x01", 32));
        $otherKey = new EncryptionKey('key-other', str_repeat("\x01", 32));
        $keyRing = $this->createStub(EncryptionKeyRing::class);
        $keyRing->method('current')->willReturn($key);
        $keyRing->method('find')->willReturnMap([
            ['key-current', $key],
            ['key-other', $otherKey],
        ]);
        $cipher = new SodiumSecretCipher($keyRing);
        $encryptedSecret = $cipher->encrypt(
            'must not appear in an error',
            SecretPurpose::AdministratorTotpSecret,
            self::RECORD_ID,
        );
        $tamperedValue = $encryptedSecret->encryptedValue;
        $tamperedValue[0] = chr(ord($tamperedValue[0]) ^ 1);
        $tamperedSecret = new EncryptedSecret(
            $tamperedValue,
            $encryptedSecret->nonce,
            $encryptedSecret->keyId,
        );
        $tamperedNonce = $encryptedSecret->nonce;
        $tamperedNonce[0] = chr(ord($tamperedNonce[0]) ^ 1);
        $nonceMismatch = new EncryptedSecret(
            $encryptedSecret->encryptedValue,
            $tamperedNonce,
            $encryptedSecret->keyId,
        );
        $keyIdMismatch = new EncryptedSecret(
            $encryptedSecret->encryptedValue,
            $encryptedSecret->nonce,
            $otherKey->id,
        );

        foreach (
            [
                'ciphertext' => fn (): string => $cipher->decrypt(
                    $tamperedSecret,
                    SecretPurpose::AdministratorTotpSecret,
                    self::RECORD_ID,
                ),
                'nonce' => fn (): string => $cipher->decrypt(
                    $nonceMismatch,
                    SecretPurpose::AdministratorTotpSecret,
                    self::RECORD_ID,
                ),
                'key ID' => fn (): string => $cipher->decrypt(
                    $keyIdMismatch,
                    SecretPurpose::AdministratorTotpSecret,
                    self::RECORD_ID,
                ),
                'purpose' => fn (): string => $cipher->decrypt(
                    $encryptedSecret,
                    SecretPurpose::DiscordWebhookUrl,
                    self::RECORD_ID,
                ),
                'record ID' => fn (): string => $cipher->decrypt(
                    $encryptedSecret,
                    SecretPurpose::AdministratorTotpSecret,
                    '01991e40-9c00-7000-8000-000000000002',
                ),
            ] as $decrypt
        ) {
            try {
                $decrypt();
                self::fail('SecretDecryptionFailed was not thrown.');
            } catch (SecretDecryptionFailed $exception) {
                self::assertSame('秘密情報を復号できません。', $exception->getMessage());
                self::assertStringNotContainsString('must not appear in an error', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itRejectsAMissingHistoricalKey(): void
    {
        $keyRing = $this->createStub(EncryptionKeyRing::class);
        $keyRing->method('find')->willReturn(null);
        $cipher = new SodiumSecretCipher($keyRing);
        $encryptedSecret = new EncryptedSecret(str_repeat('c', 16), str_repeat('n', 24), 'key-missing');

        $this->expectException(SecretDecryptionFailed::class);

        $cipher->decrypt($encryptedSecret, SecretPurpose::AdministratorTotpSecret, self::RECORD_ID);
    }

    #[Test]
    public function itRejectsANonUuidV7RecordIdBeforeEncryption(): void
    {
        $keyRing = $this->createMock(EncryptionKeyRing::class);
        $keyRing->expects(self::never())->method('current');
        $cipher = new SodiumSecretCipher($keyRing);

        $this->expectException(InvalidArgumentException::class);

        $cipher->encrypt('sensitive value', SecretPurpose::AdministratorTotpSecret, 'not-a-uuid');
    }
}
