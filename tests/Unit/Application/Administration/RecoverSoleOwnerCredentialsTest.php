<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\HashAdministratorPassword;
use App\Application\Administration\RecoverSoleOwnerCredentials;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\CommonPasswordChecker;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RecoverSoleOwnerCredentialsTest extends TestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000550';
    private const string TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private AdministratorPasswordHasher&MockObject $passwordHasher;
    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private AdministratorRecoveryCodeAlgorithm&MockObject $recoveryCodeAlgorithm;
    private SecretCipher&MockObject $secretCipher;
    private SoleOwnerCredentialRecoveryRepository&MockObject $repository;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->repository = $this->createMock(SoleOwnerCredentialRecoveryRepository::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itDelegatesRecoveryTargetDiscovery(): void
    {
        $target = $this->target();
        $this->repository->expects(self::once())->method('findTarget')->willReturn($target);

        self::assertSame($target, $this->service()->findTarget());
    }

    #[Test]
    public function itReplacesCredentialsAndReturnsOneTimeRecoveryCodes(): void
    {
        $target = $this->target();
        $now = new DateTimeImmutable('2026-09-07 01:00:00.123456+00:00');
        $plainCodes = $this->plainCodes();
        $passwordHash = password_hash('unit-test-password', PASSWORD_ARGON2ID);
        $encryptedSecret = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '123456', $now)
            ->willReturn(59_611_320);
        $this->passwordHasher->expects(self::once())
            ->method('hash')
            ->with('a unique passphrase!')
            ->willReturn($passwordHash);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->idGenerator->expects(self::exactly(10))
            ->method('generate')
            ->willReturn(...$this->recoveryCodeIds());
        $this->secretCipher->expects(self::once())
            ->method('encrypt')
            ->with(self::TOTP_SECRET, SecretPurpose::AdministratorTotpSecret, self::OWNER_ID)
            ->willReturn($encryptedSecret);
        $this->repository->expects(self::once())
            ->method('recover')
            ->with(
                $target,
                $passwordHash,
                self::callback(static function (AdministratorTotpCredential $credential) use ($encryptedSecret): bool {
                    self::assertSame(self::OWNER_ID, $credential->administratorId);
                    self::assertSame($encryptedSecret, $credential->encryptedSecret);
                    self::assertSame(59_611_320, $credential->lastAcceptedTimeStep);

                    return true;
                }),
                self::callback(static function (array $codes) use ($plainCodes, $now): bool {
                    self::assertCount(10, $codes);
                    foreach ($codes as $index => $code) {
                        self::assertInstanceOf(AdministratorRecoveryCode::class, $code);
                        self::assertSame(self::OWNER_ID, $code->administratorId);
                        self::assertSame(hash('sha256', $plainCodes[$index]), $code->codeHash);
                        self::assertSame($now, $code->createdAt);
                        self::assertNull($code->usedAt);
                    }

                    return true;
                }),
                $now,
            );

        $result = $this->service()->recover(
            $target,
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );

        self::assertNotNull($result);
        self::assertSame(self::OWNER_ID, $result->administratorId);
        self::assertSame($plainCodes, $result->consumeRecoveryCodes());
        $this->expectException(LogicException::class);
        $result->consumeRecoveryCodes();
    }

    #[Test]
    public function itDoesNotChangeCredentialsWhenTheTotpCodeDoesNotMatch(): void
    {
        $now = new DateTimeImmutable('2026-09-07 01:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '000000', $now)
            ->willReturn(null);
        $this->passwordHasher->expects(self::never())->method('hash');
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->repository->expects(self::never())->method('recover');
        $this->idGenerator->expects(self::never())->method('generate');

        self::assertNull($this->service()->recover(
            $this->target(),
            'a unique passphrase!',
            self::TOTP_SECRET,
            '000000',
        ));
    }

    #[Test]
    public function itRejectsDuplicateRecoveryCodeIdsBeforeEncryptionOrPersistence(): void
    {
        $this->clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-07 01:00:00+00:00'));
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_611_320);
        $this->passwordHasher->expects(self::once())->method('hash')->willReturn(password_hash('test', PASSWORD_ARGON2ID));
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($this->plainCodes());
        $this->recoveryCodeAlgorithm->expects(self::exactly(2))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->idGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturn($this->recoveryCodeIds()[0], $this->recoveryCodeIds()[0]);
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->repository->expects(self::never())->method('recover');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('回復コードID生成結果に重複があります。');

        $this->service()->recover(
            $this->target(),
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );
    }

    private function service(): RecoverSoleOwnerCredentials
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);

        return new RecoverSoleOwnerCredentials(
            new HashAdministratorPassword(
                new AdministratorPasswordPolicy($commonPasswordChecker),
                $this->passwordHasher,
            ),
            $this->totpAlgorithm,
            $this->recoveryCodeAlgorithm,
            $this->secretCipher,
            $this->repository,
            $this->idGenerator,
            $this->clock,
        );
    }

    private function target(): SoleOwnerRecoveryTarget
    {
        return new SoleOwnerRecoveryTarget(
            self::OWNER_ID,
            'system.owner',
            '管理者',
            3,
        );
    }

    /** @return list<string> */
    private function plainCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = sprintf('%08X-AAAAAAAA-BBBBBBBB-CCCCCCCC', $index);
        }

        return $codes;
    }

    /** @return list<string> */
    private function recoveryCodeIds(): array
    {
        $ids = [];
        for ($index = 0; $index < 10; ++$index) {
            $ids[] = sprintf('01990d4a-0000-7000-8000-%012d', 560 + $index);
        }

        return $ids;
    }
}
