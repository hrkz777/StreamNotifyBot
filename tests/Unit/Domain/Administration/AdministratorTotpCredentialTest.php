<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\EncryptedSecret;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorTotpCredentialTest extends TestCase
{
    #[Test]
    public function itAcceptsAnUnconsumedEncryptedCredential(): void
    {
        $credential = new AdministratorTotpCredential(
            '01990d4a-0000-7000-8000-000000000150',
            $this->encryptedSecret(),
            null,
        );

        self::assertNull($credential->lastAcceptedTimeStep);
    }

    #[Test]
    public function itRejectsANegativeLastAcceptedTimeStep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('最終受理TOTP時刻ステップは0以上で指定してください。');

        new AdministratorTotpCredential(
            '01990d4a-0000-7000-8000-000000000150',
            $this->encryptedSecret(),
            -1,
        );
    }

    private function encryptedSecret(): EncryptedSecret
    {
        return new EncryptedSecret(
            str_repeat('c', EncryptedSecret::AUTHENTICATION_TAG_BYTE_LENGTH),
            str_repeat('n', EncryptedSecret::NONCE_BYTE_LENGTH),
            'primary',
        );
    }
}
