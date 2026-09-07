<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\CreateInitialOwner;
use App\Application\Administration\HashAdministratorPassword;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\CommonPasswordChecker;
use App\Domain\Administration\InitialOwnerRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Presentation\Console\CreateInitialOwnerCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateInitialOwnerCommandTest extends TestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000750';
    private const string TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const string PROVISIONING_URI = 'otpauth://totp/StreamNotifyBot%3Asystem.owner?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=StreamNotifyBot';

    #[Test]
    public function itCreatesTheInitialOwnerInteractivelyAndShowsRecoveryCodesOnce(): void
    {
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::once())->method('generateSecret')->willReturn(self::TOTP_SECRET);
        $totpAlgorithm->expects(self::once())
            ->method('provisioningUri')
            ->with(self::TOTP_SECRET, 'system.owner')
            ->willReturn(self::PROVISIONING_URI);
        $totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '123456', self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(59_611_560);
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
        $repository = $this->createMock(InitialOwnerRepository::class);
        $repository->expects(self::once())->method('create');
        $idGenerator = $this->createMock(IdGenerator::class);
        $idGenerator->expects(self::exactly(11))
            ->method('generate')
            ->willReturn(self::OWNER_ID, ...$this->recoveryCodeIds());
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-07 01:04:00+00:00'));

        $tester = $this->commandTester(
            $totpAlgorithm,
            $passwordHasher,
            $recoveryCodeAlgorithm,
            $secretCipher,
            $repository,
            $idGenerator,
            $clock,
        );
        $tester->setInputs([
            'system.owner',
            '管理者',
            'a unique passphrase!',
            'a unique passphrase!',
            '123456',
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString(self::PROVISIONING_URI, $display);
        self::assertStringContainsString($plainCodes[0], $display);
        self::assertStringContainsString($plainCodes[9], $display);
        self::assertStringContainsString('初期ownerを作成しました', $display);
        self::assertStringNotContainsString('a unique passphrase!', $display);
        self::assertStringNotContainsString('123456', $display);
    }

    #[Test]
    public function itRejectsNonInteractiveExecutionBeforeReadingCredentials(): void
    {
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::never())->method('generateSecret');
        $repository = $this->createMock(InitialOwnerRepository::class);
        $repository->expects(self::never())->method('create');

        $tester = $this->commandTester(
            $totpAlgorithm,
            $this->createStub(AdministratorPasswordHasher::class),
            $this->createStub(AdministratorRecoveryCodeAlgorithm::class),
            $this->createStub(SecretCipher::class),
            $repository,
            $this->createStub(IdGenerator::class),
            $this->createStub(Clock::class),
        );

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('対話モードでのみ実行できます', $tester->getDisplay());
    }

    #[Test]
    public function itStopsBeforeGeneratingATotpSecretWhenPasswordConfirmationDoesNotMatch(): void
    {
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::never())->method('generateSecret');
        $passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $passwordHasher->expects(self::never())->method('hash');
        $repository = $this->createMock(InitialOwnerRepository::class);
        $repository->expects(self::never())->method('create');

        $tester = $this->commandTester(
            $totpAlgorithm,
            $passwordHasher,
            $this->createStub(AdministratorRecoveryCodeAlgorithm::class),
            $this->createStub(SecretCipher::class),
            $repository,
            $this->createStub(IdGenerator::class),
            $this->createStub(Clock::class),
        );
        $tester->setInputs([
            'system.owner',
            '管理者',
            'first passphrase!',
            'second passphrase!',
        ]);

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('入力したパスワードが一致しません', $tester->getDisplay());
        self::assertStringNotContainsString('first passphrase!', $tester->getDisplay());
        self::assertStringNotContainsString('second passphrase!', $tester->getDisplay());
    }

    private function commandTester(
        AdministratorTotpAlgorithm $totpAlgorithm,
        AdministratorPasswordHasher $passwordHasher,
        AdministratorRecoveryCodeAlgorithm $recoveryCodeAlgorithm,
        SecretCipher $secretCipher,
        InitialOwnerRepository $repository,
        IdGenerator $idGenerator,
        Clock $clock,
    ): CommandTester {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);
        $createInitialOwner = new CreateInitialOwner(
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

        return new CommandTester(new CreateInitialOwnerCommand(
            $createInitialOwner,
            new BeginAdministratorTotpEnrollment($totpAlgorithm),
        ));
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
            $ids[] = sprintf('01990d4a-0000-7000-8000-%012d', 760 + $index);
        }

        return $ids;
    }
}
