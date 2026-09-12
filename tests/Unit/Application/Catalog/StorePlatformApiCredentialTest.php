<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Application\Catalog\StorePlatformApiCredential;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredential;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\IdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StorePlatformApiCredentialTest extends TestCase
{
    private const CREDENTIAL_ID = '01990d4a-0000-7000-8000-000000001601';

    #[Test]
    public function itEncryptsAndStoresThePlatformConfiguration(): void
    {
        $configuration = PlatformApiCredentialConfiguration::twitch('client-id', 'client-secret');
        $repository = $this->createMock(PlatformApiCredentialRepository::class);
        $repository->expects(self::once())->method('save')->with(self::callback(static function (PlatformApiCredential $credential): bool {
            return $credential->id === self::CREDENTIAL_ID && $credential->platform === Platform::Twitch;
        }));
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::once())->method('encrypt')->with('{"client_id":"client-id","client_secret":"client-secret"}', SecretPurpose::PlatformApiCredential, self::CREDENTIAL_ID)->willReturn(new EncryptedSecret(str_repeat('a', 16), str_repeat('b', 24), 'primary'));
        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator->method('generate')->willReturn(self::CREDENTIAL_ID);

        $id = (new StorePlatformApiCredential($repository, $cipher, $idGenerator))->store($configuration);

        self::assertSame(self::CREDENTIAL_ID, $id);
    }
}
