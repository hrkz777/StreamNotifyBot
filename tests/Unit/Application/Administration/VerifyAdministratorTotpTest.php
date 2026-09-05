<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\VerifyAdministratorTotp;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class VerifyAdministratorTotpTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private AdministratorTotpCredentialRepository&MockObject $credentialRepository;
    private SecretCipher&MockObject $secretCipher;
    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private Clock&Stub $clock;

    protected function setUp(): void
    {
        $this->credentialRepository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->clock = $this->createStub(Clock::class);
    }

    #[Test]
    public function itReturnsFalseWithoutDecryptingWhenTheCredentialDoesNotExist(): void
    {
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByAdministratorId')
            ->with(self::ADMINISTRATOR_ID)
            ->willReturn(null);
        $this->secretCipher->expects(self::never())->method('decrypt');
        $this->totpAlgorithm->expects(self::never())->method('matchTimeStep');

        self::assertFalse($this->service()->verify(self::ADMINISTRATOR_ID, '123456'));
    }

    #[Test]
    public function itReturnsFalseWithoutUpdatingWhenTheCodeDoesNotMatch(): void
    {
        $now = new DateTimeImmutable('2026-09-06 00:00:00+00:00');
        $this->expectCredentialAndDecryption();
        $this->clock->method('now')->willReturn($now);
        $this->totpAlgorithm
            ->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::SECRET, '000000', $now)
            ->willReturn(null);
        $this->credentialRepository->expects(self::never())->method('acceptTimeStep');

        self::assertFalse($this->service()->verify(self::ADMINISTRATOR_ID, '000000'));
    }

    #[Test]
    public function itAcceptsTheMatchedTimeStep(): void
    {
        $now = new DateTimeImmutable('2026-09-06 00:00:00+00:00');
        $this->expectCredentialAndDecryption();
        $this->clock->method('now')->willReturn($now);
        $this->totpAlgorithm
            ->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::SECRET, '123456', $now)
            ->willReturn(59_606_880);
        $this->credentialRepository
            ->expects(self::once())
            ->method('acceptTimeStep')
            ->with(self::ADMINISTRATOR_ID, 59_606_880)
            ->willReturn(true);

        self::assertTrue($this->service()->verify(self::ADMINISTRATOR_ID, '123456'));
    }

    #[Test]
    public function itReturnsFalseWhenTheMatchedTimeStepWasAlreadyAccepted(): void
    {
        $this->expectCredentialAndDecryption();
        $now = new DateTimeImmutable('2026-09-06 00:00:00+00:00');
        $this->clock->method('now')->willReturn($now);
        $this->totpAlgorithm
            ->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::SECRET, '123456', $now)
            ->willReturn(59_606_880);
        $this->credentialRepository
            ->expects(self::once())
            ->method('acceptTimeStep')
            ->with(self::ADMINISTRATOR_ID, 59_606_880)
            ->willReturn(false);

        self::assertFalse($this->service()->verify(self::ADMINISTRATOR_ID, '123456'));
    }

    private function expectCredentialAndDecryption(): void
    {
        $credential = new AdministratorTotpCredential(
            self::ADMINISTRATOR_ID,
            new EncryptedSecret(str_repeat('c', 16), str_repeat('n', 24), 'primary'),
            null,
        );
        $this->credentialRepository
            ->expects(self::once())
            ->method('findByAdministratorId')
            ->with(self::ADMINISTRATOR_ID)
            ->willReturn($credential);
        $this->secretCipher
            ->expects(self::once())
            ->method('decrypt')
            ->with($credential->encryptedSecret, SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)
            ->willReturn(self::SECRET);
    }

    private function service(): VerifyAdministratorTotp
    {
        return new VerifyAdministratorTotp(
            $this->credentialRepository,
            $this->secretCipher,
            $this->totpAlgorithm,
            $this->clock,
        );
    }
}
