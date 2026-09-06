<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\ConfirmAdministratorTotpEnrollment;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpEnrollmentRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ConfirmAdministratorTotpEnrollmentTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private AdministratorRecoveryCodeAlgorithm&MockObject $recoveryCodeAlgorithm;
    private SecretCipher&MockObject $secretCipher;
    private AdministratorTotpEnrollmentRepository&MockObject $enrollmentRepository;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->enrollmentRepository = $this->createMock(AdministratorTotpEnrollmentRepository::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itConfirmsTheMatchedStepAndReturnsRecoveryCodesOnlyAfterPersistence(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00.123456+00:00');
        $plainCodes = $this->plainCodes();
        $encryptedSecret = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::SECRET, '123456', $now)
            ->willReturn(59_608_320);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->idGenerator->expects(self::exactly(10))
            ->method('generate')
            ->willReturn(...$this->recoveryCodeIds());
        $this->secretCipher->expects(self::once())
            ->method('encrypt')
            ->with(self::SECRET, SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)
            ->willReturn($encryptedSecret);
        $this->enrollmentRepository->expects(self::once())
            ->method('confirm')
            ->with(
                self::callback(static function (AdministratorTotpCredential $credential) use ($encryptedSecret): bool {
                    self::assertSame(self::ADMINISTRATOR_ID, $credential->administratorId);
                    self::assertSame($encryptedSecret, $credential->encryptedSecret);
                    self::assertSame(59_608_320, $credential->lastAcceptedTimeStep);

                    return true;
                }),
                self::callback(static function (array $codes) use ($now, $plainCodes): bool {
                    self::assertCount(10, $codes);
                    foreach ($codes as $index => $code) {
                        self::assertInstanceOf(AdministratorRecoveryCode::class, $code);
                        self::assertSame(self::ADMINISTRATOR_ID, $code->administratorId);
                        self::assertSame(hash('sha256', $plainCodes[$index]), $code->codeHash);
                        self::assertSame($now, $code->createdAt);
                        self::assertNull($code->usedAt);
                    }

                    return true;
                }),
                $now,
            )
            ->willReturn(true);

        $result = $this->service()->confirm(self::ADMINISTRATOR_ID, self::SECRET, '123456');

        self::assertNotNull($result);
        self::assertSame($plainCodes, $result->recoveryCodes);
    }

    #[Test]
    public function itDoesNotGenerateEncryptOrPersistWhenTheConfirmationCodeDoesNotMatch(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::SECRET, '000000', $now)
            ->willReturn(null);
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->enrollmentRepository->expects(self::never())->method('confirm');
        $this->idGenerator->expects(self::never())->method('generate');

        self::assertNull($this->service()->confirm(self::ADMINISTRATOR_ID, self::SECRET, '000000'));
    }

    #[Test]
    public function itDoesNotReturnRecoveryCodesWhenPersistenceIsRejected(): void
    {
        $this->expectSuccessfulPreparation();
        $this->enrollmentRepository->expects(self::once())->method('confirm')->willReturn(false);

        self::assertNull($this->service()->confirm(self::ADMINISTRATOR_ID, self::SECRET, '123456'));
    }

    #[Test]
    public function itRejectsAnInvalidRecoveryCodeCountBeforeEncryptionOrPersistence(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_608_320);
        $this->recoveryCodeAlgorithm->expects(self::once())
            ->method('generate')
            ->willReturn(array_slice($this->plainCodes(), 0, 9));
        $this->recoveryCodeAlgorithm->expects(self::never())->method('hash');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->enrollmentRepository->expects(self::never())->method('confirm');
        $this->idGenerator->expects(self::never())->method('generate');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('回復コード生成結果は10件である必要があります。');

        $this->service()->confirm(self::ADMINISTRATOR_ID, self::SECRET, '123456');
    }

    #[Test]
    public function itRejectsDuplicateRecoveryCodesBeforeEncryptionOrPersistence(): void
    {
        $plainCodes = $this->plainCodes();
        $plainCodes[1] = $plainCodes[0];
        $this->clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-06 12:00:00+00:00'));
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_608_320);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(2))
            ->method('hash')
            ->willReturn(str_repeat('a', 64));
        $this->idGenerator->expects(self::once())
            ->method('generate')
            ->willReturn($this->recoveryCodeIds()[0]);
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->enrollmentRepository->expects(self::never())->method('confirm');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('回復コード生成結果に重複があります。');

        $this->service()->confirm(self::ADMINISTRATOR_ID, self::SECRET, '123456');
    }

    private function expectSuccessfulPreparation(): void
    {
        $this->clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-06 12:00:00+00:00'));
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_608_320);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($this->plainCodes());
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->idGenerator->expects(self::exactly(10))->method('generate')->willReturn(...$this->recoveryCodeIds());
        $this->secretCipher->expects(self::once())->method('encrypt')
            ->willReturn(new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'));
    }

    private function service(): ConfirmAdministratorTotpEnrollment
    {
        return new ConfirmAdministratorTotpEnrollment(
            $this->totpAlgorithm,
            $this->recoveryCodeAlgorithm,
            $this->secretCipher,
            $this->enrollmentRepository,
            $this->idGenerator,
            $this->clock,
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
            $ids[] = sprintf('01990d4a-0000-7000-8000-%012d', 160 + $index);
        }

        return $ids;
    }
}
