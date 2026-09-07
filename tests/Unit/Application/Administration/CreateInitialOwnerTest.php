<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\CreateInitialOwner;
use App\Application\Administration\HashAdministratorPassword;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\CommonPasswordChecker;
use App\Domain\Administration\InitialOwnerRepository;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
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

final class CreateInitialOwnerTest extends TestCase
{
    private const string OWNER_ID = '01990d4a-0000-7000-8000-000000000150';
    private const string TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private AdministratorPasswordHasher&MockObject $passwordHasher;
    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private AdministratorRecoveryCodeAlgorithm&MockObject $recoveryCodeAlgorithm;
    private SecretCipher&MockObject $secretCipher;
    private InitialOwnerRepository&MockObject $repository;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->repository = $this->createMock(InitialOwnerRepository::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itCreatesAnOwnerWithTheMatchedStepAndReturnsRecoveryCodesAfterPersistence(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00.123456+00:00');
        $plainCodes = $this->plainCodes();
        $encryptedSecret = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->expectSuccessfulPreparation($now, $plainCodes, $encryptedSecret);
        $this->repository->expects(self::once())
            ->method('create')
            ->with(
                self::callback(static function (Administrator $owner) use ($now): bool {
                    self::assertSame(self::OWNER_ID, $owner->id);
                    self::assertSame('system.owner', $owner->loginId);
                    self::assertSame('管理者', $owner->displayName);
                    self::assertSame(AdministratorRole::Owner, $owner->role);
                    self::assertSame(AdministratorStatus::Pending, $owner->status);
                    self::assertSame('argon2id', password_get_info($owner->passwordHash ?? '')['algoName']);
                    self::assertSame(1, $owner->authenticationVersion);
                    self::assertSame($now, $owner->passwordChangedAt);
                    self::assertSame($now, $owner->createdAt);
                    self::assertSame($now, $owner->updatedAt);
                    self::assertSame(0, $owner->lockVersion);

                    return true;
                }),
                self::callback(static function (AdministratorTotpCredential $credential) use ($encryptedSecret): bool {
                    self::assertSame(self::OWNER_ID, $credential->administratorId);
                    self::assertSame($encryptedSecret, $credential->encryptedSecret);
                    self::assertSame(59_608_319, $credential->lastAcceptedTimeStep);

                    return true;
                }),
                self::callback(static function (array $codes) use ($now, $plainCodes): bool {
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

        $result = $this->service()->create(
            ' SYSTEM.OWNER ',
            ' 管理者 ',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );

        self::assertNotNull($result);
        self::assertSame(self::OWNER_ID, $result->administratorId);
        self::assertSame($plainCodes, $result->consumeRecoveryCodes());
    }

    #[Test]
    public function itDoesNotHashGenerateEncryptOrPersistWhenTheConfirmationCodeDoesNotMatch(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '000000', $now)
            ->willReturn(null);
        $this->passwordHasher->expects(self::never())->method('hash');
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->repository->expects(self::never())->method('create');
        $this->idGenerator->expects(self::never())->method('generate');

        self::assertNull($this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '000000',
        ));
    }

    #[Test]
    public function itRejectsAnInvalidRecoveryCodeCountBeforeEncryptionOrPersistence(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_608_320);
        $this->passwordHasher->expects(self::once())->method('hash')->willReturn('$argon2id$generated');
        $this->idGenerator->expects(self::once())->method('generate')->willReturn(self::OWNER_ID);
        $this->recoveryCodeAlgorithm->expects(self::once())
            ->method('generate')
            ->willReturn(array_slice($this->plainCodes(), 0, 9));
        $this->recoveryCodeAlgorithm->expects(self::never())->method('hash');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->repository->expects(self::never())->method('create');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('回復コード生成結果は10件である必要があります。');

        $this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );
    }

    #[Test]
    public function itRejectsDuplicateRecoveryCodeHashesBeforeEncryptionOrPersistence(): void
    {
        $this->clock->expects(self::once())
            ->method('now')
            ->willReturn(new DateTimeImmutable('2026-09-06 12:00:00+00:00'));
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(59_608_320);
        $this->passwordHasher->expects(self::once())->method('hash')->willReturn($this->passwordHash());
        $this->idGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturn(self::OWNER_ID, $this->recoveryCodeIds()[0]);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($this->plainCodes());
        $this->recoveryCodeAlgorithm->expects(self::exactly(2))->method('hash')->willReturn(str_repeat('a', 64));
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->repository->expects(self::never())->method('create');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('回復コード生成結果に重複があります。');

        $this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );
    }

    #[Test]
    public function itDoesNotPersistWhenSecretEncryptionFails(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $plainCodes = $this->plainCodes();
        $this->expectSuccessfulPreparation(
            $now,
            $plainCodes,
            null,
        );
        $this->secretCipher->expects(self::once())
            ->method('encrypt')
            ->willThrowException(new \RuntimeException('encryption failed'));
        $this->repository->expects(self::never())->method('create');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('encryption failed');

        $this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );
    }

    #[Test]
    public function itDoesNotReturnRecoveryCodesWhenPersistenceFails(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $plainCodes = $this->plainCodes();
        $encryptedSecret = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->expectSuccessfulPreparation($now, $plainCodes, $encryptedSecret);
        $this->repository->expects(self::once())
            ->method('create')
            ->willThrowException(new InitialSetupAlreadyCompleted());
        $this->expectException(InitialSetupAlreadyCompleted::class);

        $this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
        );
    }

    #[Test]
    public function itDoesNotCreateAnOwnerWhenTheInitialSetupTokenCannotBeConsumed(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00+00:00');
        $plainCodes = $this->plainCodes();
        $encryptedSecret = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->expectSuccessfulPreparation($now, $plainCodes, $encryptedSecret);
        $this->repository->expects(self::once())
            ->method('createUsingInitialSetupToken')
            ->with(
                hash('sha256', 'expired-token'),
                self::isInstanceOf(Administrator::class),
                self::isInstanceOf(AdministratorTotpCredential::class),
                self::isArray(),
                $now,
            )
            ->willReturn(false);
        $this->repository->expects(self::never())->method('create');

        self::assertNull($this->service()->create(
            'system.owner',
            '管理者',
            'a unique passphrase!',
            self::TOTP_SECRET,
            '123456',
            'expired-token',
        ));
    }

    /** @param list<string> $plainCodes */
    private function expectSuccessfulPreparation(
        DateTimeImmutable $now,
        array $plainCodes,
        ?EncryptedSecret $encryptedSecret,
    ): void {
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(self::TOTP_SECRET, '123456', $now)
            ->willReturn(59_608_319);
        $passwordHash = $this->passwordHash();
        $this->passwordHasher->expects(self::once())
            ->method('hash')
            ->with('a unique passphrase!')
            ->willReturn($passwordHash);
        $this->idGenerator->expects(self::exactly(11))
            ->method('generate')
            ->willReturn(self::OWNER_ID, ...$this->recoveryCodeIds());
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))
            ->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        if ($encryptedSecret !== null) {
            $this->secretCipher->expects(self::once())
                ->method('encrypt')
                ->with(self::TOTP_SECRET, SecretPurpose::AdministratorTotpSecret, self::OWNER_ID)
                ->willReturn($encryptedSecret);
        }
    }

    private function service(): CreateInitialOwner
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);

        return new CreateInitialOwner(
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

    private function passwordHash(): string
    {
        return password_hash('unit-test-password', PASSWORD_ARGON2ID);
    }
}
