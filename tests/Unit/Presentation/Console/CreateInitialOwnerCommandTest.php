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
use App\Domain\System\InteractiveTerminal;
use App\Presentation\Console\CreateInitialOwnerCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class CreateInitialOwnerCommandTest extends TestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string PASSWORD = 'a unique passphrase!';
    private const string TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const string TOTP_CODE = '123456';
    private const string PROVISIONING_URI = 'otpauth://totp/StreamNotifyBot:system.owner?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private AdministratorRecoveryCodeAlgorithm&MockObject $recoveryCodeAlgorithm;
    private SecretCipher&MockObject $secretCipher;
    private InitialOwnerRepository&MockObject $repository;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->repository = $this->createMock(InitialOwnerRepository::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itCreatesTheInitialOwnerAndDisplaysEachSecretResultOnce(): void
    {
        $plainCodes = $this->plainCodes();
        $this->expectBeginEnrollment();
        $this->expectSuccessfulCreation($plainCodes);
        $this->repository->expects(self::once())->method('create');
        $tester = $this->tester();
        $tester->setInputs(['system.owner', '管理者', self::TOTP_CODE, self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, self::PROVISIONING_URI));
        self::assertSame(1, substr_count($display, self::TOTP_SECRET));
        foreach ($plainCodes as $plainCode) {
            self::assertSame(1, substr_count($display, $plainCode));
        }
        self::assertStringContainsString(self::OWNER_ID, $display);
        self::assertStringContainsString('回復コードは再表示できません', $display);
        self::assertStringNotContainsString(self::PASSWORD, $display);
        self::assertStringNotContainsString(self::TOTP_CODE, $display);
    }

    #[Test]
    public function itRejectsNonInteractiveExecutionBeforeGeneratingASecret(): void
    {
        $this->totpAlgorithm->expects(self::never())->method('generateSecret');
        $this->repository->expects(self::never())->method('create');

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('入出力が接続された対話端末でのみ実行できます', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsDifferentPasswordsBeforeCreatingTheOwner(): void
    {
        $this->expectBeginEnrollment();
        $this->totpAlgorithm->expects(self::never())->method('matchTimeStep');
        $this->repository->expects(self::never())->method('create');
        $tester = $this->tester();
        $tester->setInputs(['system.owner', '管理者', self::TOTP_CODE, self::PASSWORD, 'different passphrase!']);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('パスワードが一致しません', $display);
        self::assertStringContainsString('認証アプリから削除', $display);
        self::assertStringNotContainsString(self::PASSWORD, $display);
        self::assertStringNotContainsString('different passphrase!', $display);
    }

    #[Test]
    public function itDoesNotPersistOrDisplayRecoveryCodesWhenTotpDoesNotMatch(): void
    {
        $this->expectBeginEnrollment();
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '000000', $now)
            ->willReturn(null);
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->repository->expects(self::never())->method('create');
        $tester = $this->tester();
        $tester->setInputs(['system.owner', '管理者', '000000', self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('確認コードを検証できませんでした', $display);
        self::assertStringNotContainsString($this->plainCodes()[0], $display);
        self::assertStringNotContainsString('000000', $display);
    }

    #[Test]
    public function itHidesUnexpectedFailureDetailsAndGeneratedRecoveryCodes(): void
    {
        $plainCodes = $this->plainCodes();
        $this->expectBeginEnrollment();
        $this->expectSuccessfulCreation($plainCodes);
        $this->repository->expects(self::once())
            ->method('create')
            ->willThrowException(new RuntimeException('mysql://user:secret@example.invalid'));
        $tester = $this->tester();
        $tester->setInputs(['system.owner', '管理者', self::TOTP_CODE, self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('初期ownerの作成結果を確認できませんでした', $display);
        self::assertStringNotContainsString('mysql://', $display);
        self::assertStringNotContainsString('example.invalid', $display);
        self::assertStringNotContainsString($plainCodes[0], $display);
        self::assertStringNotContainsString(self::PASSWORD, $display);
        self::assertStringNotContainsString(self::TOTP_CODE, $display);
    }

    #[Test]
    public function itDefinesNoSecretArgumentsOrOptions(): void
    {
        $definition = (new CreateInitialOwnerCommand(
            new BeginAdministratorTotpEnrollment($this->totpAlgorithm),
            $this->createInitialOwner(),
            $this->interactiveTerminal(),
        ))->getDefinition();

        self::assertFalse($definition->hasArgument('password'));
        self::assertFalse($definition->hasArgument('totp-secret'));
        self::assertFalse($definition->hasOption('password'));
        self::assertFalse($definition->hasOption('totp-secret'));
        self::assertFalse($definition->hasOption('recovery-codes'));
    }

    #[Test]
    public function itRejectsAStreamThatIsNotAnInteractiveTerminal(): void
    {
        $this->totpAlgorithm->expects(self::never())->method('generateSecret');
        $this->repository->expects(self::never())->method('create');

        $tester = $this->tester(inputTerminal: false);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('入出力が接続された対話端末', $tester->getDisplay());
    }

    #[Test]
    public function itReportsInputInterruptionAfterShowingTotpEnrollment(): void
    {
        $this->expectBeginEnrollment();
        $this->repository->expects(self::never())->method('create');
        $tester = $this->tester();
        $tester->setInputs(['system.owner', '管理者']);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('入力が中断されました', $display);
        self::assertStringContainsString('認証アプリから削除', $display);
    }

    private function expectBeginEnrollment(): void
    {
        $this->totpAlgorithm->expects(self::once())->method('generateSecret')->willReturn(self::TOTP_SECRET);
        $this->totpAlgorithm->expects(self::once())
            ->method('provisioningUri')
            ->with(self::TOTP_SECRET, 'system.owner')
            ->willReturn(self::PROVISIONING_URI);
    }

    /** @param list<string> $plainCodes */
    private function expectSuccessfulCreation(array $plainCodes): void
    {
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, self::TOTP_CODE, $now)
            ->willReturn(59_611_200);
        $this->idGenerator->expects(self::exactly(11))
            ->method('generate')
            ->willReturn(self::OWNER_ID, ...$this->recoveryCodeIds());
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->secretCipher->expects(self::once())
            ->method('encrypt')
            ->willReturn(new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'));
    }

    private function tester(bool $inputTerminal = true, bool $outputTerminal = true): CommandTester
    {
        return new CommandTester(new CreateInitialOwnerCommand(
            new BeginAdministratorTotpEnrollment($this->totpAlgorithm),
            $this->createInitialOwner(),
            $this->interactiveTerminal($inputTerminal, $outputTerminal),
        ));
    }

    private function interactiveTerminal(bool $input = true, bool $output = true): InteractiveTerminal
    {
        $terminal = $this->createStub(InteractiveTerminal::class);
        $terminal->method('hasInteractiveInput')->willReturn($input);
        $terminal->method('hasInteractiveOutput')->willReturn($output);

        return $terminal;
    }

    private function createInitialOwner(): CreateInitialOwner
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);
        $passwordHasher = $this->createStub(AdministratorPasswordHasher::class);
        $passwordHasher->method('hash')->willReturn(password_hash('unit-test-password', PASSWORD_ARGON2ID));

        return new CreateInitialOwner(
            new HashAdministratorPassword(
                new AdministratorPasswordPolicy($commonPasswordChecker),
                $passwordHasher,
            ),
            $this->totpAlgorithm,
            $this->recoveryCodeAlgorithm,
            $this->secretCipher,
            $this->repository,
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
