<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\ReencryptAdministratorTotpCredentials;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\EncryptionKey;
use App\Domain\Security\EncryptionKeyRing;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReencryptAdministratorTotpCredentialsTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000650';

    #[Test]
    public function itReencryptsOnlyCredentialsUsingANonCurrentKey(): void
    {
        $oldCredential = $this->credential('old-key');
        $currentCredential = new AdministratorTotpCredential(
            '01990d4a-0000-7000-8000-000000000651',
            new EncryptedSecret(str_repeat('c', 16), str_repeat('n', 24), 'current-key'),
            12,
        );
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::once())->method('findAll')->willReturn([$oldCredential, $currentCredential]);
        $replacement = new EncryptedSecret(str_repeat('r', 16), str_repeat('x', 24), 'current-key');
        $repository->expects(self::once())
            ->method('replaceEncryptedSecrets')
            ->with(self::callback(static fn (array $credentials): bool => count($credentials) === 1 && $credentials[0] instanceof AdministratorTotpCredential && $credentials[0]->encryptedSecret === $replacement && $credentials[0]->lastAcceptedTimeStep === 11));
        $keyRing = $this->createStub(EncryptionKeyRing::class);
        $keyRing->method('current')->willReturn(new EncryptionKey('current-key', str_repeat('k', 32)));
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::once())
            ->method('decrypt')
            ->with($oldCredential->encryptedSecret, SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)
            ->willReturn('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $cipher->expects(self::once())
            ->method('encrypt')
            ->with('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)
            ->willReturn($replacement);

        self::assertSame(1, (new ReencryptAdministratorTotpCredentials($repository, $keyRing, $cipher))->reencrypt());
    }

    #[Test]
    public function itDoesNotWriteWhenAllCredentialsAlreadyUseTheCurrentKey(): void
    {
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::once())->method('findAll')->willReturn([$this->credential('current-key')]);
        $repository->expects(self::never())->method('replaceEncryptedSecrets');
        $keyRing = $this->createStub(EncryptionKeyRing::class);
        $keyRing->method('current')->willReturn(new EncryptionKey('current-key', str_repeat('k', 32)));
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::never())->method('decrypt');
        $cipher->expects(self::never())->method('encrypt');

        self::assertSame(0, (new ReencryptAdministratorTotpCredentials($repository, $keyRing, $cipher))->reencrypt());
    }

    private function credential(string $keyId): AdministratorTotpCredential
    {
        return new AdministratorTotpCredential(
            self::ADMINISTRATOR_ID,
            new EncryptedSecret(str_repeat('c', 16), str_repeat('n', 24), $keyId),
            11,
        );
    }
}
