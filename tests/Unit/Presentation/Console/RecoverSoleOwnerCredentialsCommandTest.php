<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\HashAdministratorPassword;
use App\Application\Administration\RecoverSoleOwnerCredentials;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\CommonPasswordChecker;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Presentation\Console\RecoverSoleOwnerCredentialsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RecoverSoleOwnerCredentialsCommandTest extends TestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000650';
    private const string TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const string PROVISIONING_URI = 'otpauth://totp/StreamNotifyBot%3Asystem.owner?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=StreamNotifyBot';

    #[Test]
    public function itRecoversTheSoleOwnerInteractivelyAndShowsRecoveryCodesOnce(): void
    {
        $target = $this->target();
        $repository = $this->createMock(SoleOwnerCredentialRecoveryRepository::class);
        $repository->expects(self::once())->method('findTarget')->willReturn($target);
        $repository->expects(self::once())->method('recover');
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::once())->method('generateSecret')->willReturn(self::TOTP_SECRET);
        $totpAlgorithm->expects(self::once())
            ->method('provisioningUri')
            ->with(self::TOTP_SECRET, 'system.owner')
            ->willReturn(self::PROVISIONING_URI);
        $totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '123456', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(59_611_440);
        $passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $passwordHasher->expects(self::once())
            ->method('hash')
            ->with('a unique passphrase!')
            ->willReturn(password_hash('unit-test-password', PASSWORD_ARGON2ID));
        $recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $plainCodes = $this->plainCodes();
        $recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $recoveryCodeAlgorithm->expects(self::exactly(10))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $secretCipher = $this->createMock(SecretCipher::class);
        $secretCipher->expects(self::once())
            ->method('encrypt')
            ->willReturn(new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'));
        $idGenerator = $this->createMock(IdGenerator::class);
        $idGenerator->expects(self::exactly(10))
            ->method('generate')
            ->willReturn(...$this->recoveryCodeIds());
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-07 01:02:00+00:00'));

        $tester = $this->commandTester(
            $repository,
            $totpAlgorithm,
            $passwordHasher,
            $recoveryCodeAlgorithm,
            $secretCipher,
            $idGenerator,
            $clock,
        );
        $tester->setInputs(['yes', 'a unique passphrase!', 'a unique passphrase!', '123456']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('system.owner', $display);
        self::assertStringContainsString(self::PROVISIONING_URI, $display);
        self::assertStringContainsString($plainCodes[0], $display);
        self::assertStringContainsString($plainCodes[9], $display);
        self::assertStringContainsString('単独ownerの資格情報を回復しました', $display);
        self::assertStringNotContainsString('a unique passphrase!', $display);
        self::assertStringNotContainsString('123456', $display);
    }

    #[Test]
    public function itRejectsNonInteractiveExecutionBeforeReadingTheRecoveryTarget(): void
    {
        $repository = $this->createMock(SoleOwnerCredentialRecoveryRepository::class);
        $repository->expects(self::never())->method('findTarget');
        $totpAlgorithm = $this->createStub(AdministratorTotpAlgorithm::class);

        $tester = $this->commandTester(
            $repository,
            $totpAlgorithm,
            $this->createStub(AdministratorPasswordHasher::class),
            $this->createStub(AdministratorRecoveryCodeAlgorithm::class),
            $this->createStub(SecretCipher::class),
            $this->createStub(IdGenerator::class),
            $this->createStub(Clock::class),
        );

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('対話モードでのみ実行できます', $tester->getDisplay());
    }

    #[Test]
    public function itStopsBeforeGeneratingATotpSecretWhenPasswordConfirmationDoesNotMatch(): void
    {
        $repository = $this->createMock(SoleOwnerCredentialRecoveryRepository::class);
        $repository->expects(self::once())->method('findTarget')->willReturn($this->target());
        $repository->expects(self::never())->method('recover');
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::never())->method('generateSecret');
        $passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $passwordHasher->expects(self::never())->method('hash');

        $tester = $this->commandTester(
            $repository,
            $totpAlgorithm,
            $passwordHasher,
            $this->createStub(AdministratorRecoveryCodeAlgorithm::class),
            $this->createStub(SecretCipher::class),
            $this->createStub(IdGenerator::class),
            $this->createStub(Clock::class),
        );
        $tester->setInputs(['yes', 'first passphrase!', 'second passphrase!']);

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('入力したパスワードが一致しません', $tester->getDisplay());
        self::assertStringNotContainsString('first passphrase!', $tester->getDisplay());
        self::assertStringNotContainsString('second passphrase!', $tester->getDisplay());
    }

    private function commandTester(
        SoleOwnerCredentialRecoveryRepository $repository,
        AdministratorTotpAlgorithm $totpAlgorithm,
        AdministratorPasswordHasher $passwordHasher,
        AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        SecretCipher $secretCipher,
        IdGenerator $idGenerator,
        Clock $clock,
    ): CommandTester {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);
        $recover = new RecoverSoleOwnerCredentials(
            new HashAdministratorPassword(
                new AdministratorPasswordPolicy($commonPasswordChecker),
                $passwordHasher,
            ),
            $totpAlgorithm,
            $recoveryCodeAlgorithm,
            $secretCipher,
            $repository,
            $idGenerator,
            $clock,
        );

        return new CommandTester(new RecoverSoleOwnerCredentialsCommand(
            $recover,
            new BeginAdministratorTotpEnrollment($totpAlgorithm),
        ));
    }

    private function target(): SoleOwnerRecoveryTarget
    {
        return new SoleOwnerRecoveryTarget(
            self::OWNER_ID,
            'system.owner',
            '管理者',
            4,
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
            $ids[] = sprintf('01990d4a-0000-7000-8000-%012d', 660 + $index);
        }

        return $ids;
    }
}
