<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\LoadPlatformApiCredential;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoadPlatformApiCredentialTest extends TestCase
{
    private const CREDENTIAL_ID = '01990d4a-0000-7000-8000-000000001602';

    #[Test]
    public function itDecryptsAndLoadsThePlatformConfiguration(): void
    {
        $encrypted = new EncryptedSecret(str_repeat('a', 16), str_repeat('b', 24), 'primary');
        $repository = $this->createMock(PlatformApiCredentialRepository::class);
        $repository->expects(self::once())->method('findByPlatform')->with(Platform::TwitCasting)->willReturn(new PlatformApiCredential(self::CREDENTIAL_ID, Platform::TwitCasting, $encrypted));
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::once())->method('decrypt')->with($encrypted, SecretPurpose::PlatformApiCredential, self::CREDENTIAL_ID)->willReturn('{"client_id":"client-id","client_secret":"client-secret"}');

        $configuration = (new LoadPlatformApiCredential($repository, $cipher))->load(Platform::TwitCasting);

        self::assertNotNull($configuration);
        self::assertSame('client-id', $configuration->value('client_id'));
        self::assertSame('client-secret', $configuration->value('client_secret'));
    }
}
