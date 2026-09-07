<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\AcceptAdministratorInvitation;
use App\Application\Administration\HashAdministratorPassword;
use App\Domain\Administration\AdministratorInvitationAcceptanceRepository;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\CommonPasswordChecker;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeAlgorithm;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AcceptAdministratorInvitationTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000190';

    private AdministratorInvitationAcceptanceRepository&MockObject $repository;
    private AdministratorTotpAlgorithm&MockObject $totpAlgorithm;
    private AdministratorRecoveryCodeAlgorithm&MockObject $recoveryCodeAlgorithm;
    private SecretCipher&MockObject $secretCipher;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AdministratorInvitationAcceptanceRepository::class);
        $this->totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $this->recoveryCodeAlgorithm = $this->createMock(AdministratorRecoveryCodeAlgorithm::class);
        $this->secretCipher = $this->createMock(SecretCipher::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itPersistsAcceptedCredentialsAndReturnsRecoveryCodesOnce(): void
    {
        $now = new DateTimeImmutable('2026-09-08 12:00:00+00:00');
        $plainCodes = $this->plainCodes();
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->repository->expects(self::once())->method('findTargetAdministratorId')
            ->with(hash('sha256', 'invitation-token'), $now)->willReturn(self::ADMINISTRATOR_ID);
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->with('secret', '123456', $now)->willReturn(59_608_320);
        $this->recoveryCodeAlgorithm->expects(self::once())->method('generate')->willReturn($plainCodes);
        $this->recoveryCodeAlgorithm->expects(self::exactly(10))->method('hash')
            ->willReturnCallback(static fn (string $code): string => hash('sha256', $code));
        $this->idGenerator->expects(self::exactly(10))->method('generate')->willReturn(...$this->recoveryCodeIds());
        $encrypted = new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary');
        $this->secretCipher->expects(self::once())->method('encrypt')
            ->with('secret', SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)->willReturn($encrypted);
        $this->repository->expects(self::once())->method('accept')->with(
            hash('sha256', 'invitation-token'),
            self::ADMINISTRATOR_ID,
            '$argon2id$hash',
            self::callback(static fn (AdministratorTotpCredential $credential): bool => $credential->encryptedSecret === $encrypted),
            self::callback(static function (array $codes) use ($now): bool {
                self::assertCount(10, $codes);
                self::assertContainsOnlyInstancesOf(AdministratorRecoveryCode::class, $codes);
                self::assertSame($now, $codes[0]->createdAt);

                return true;
            }),
            $now,
        )->willReturn(true);

        $result = $this->service()->accept('invitation-token', 'a unique passphrase!', 'secret', '123456');

        self::assertNotNull($result);
        self::assertSame(self::ADMINISTRATOR_ID, $result->administratorId);
        self::assertSame($plainCodes, $result->consumeRecoveryCodes());
        $this->expectException(\LogicException::class);
        $result->consumeRecoveryCodes();
    }

    #[Test]
    public function itDoesNotProcessAnUnavailableInvitation(): void
    {
        $now = new DateTimeImmutable('2026-09-08 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->repository->expects(self::once())->method('findTargetAdministratorId')->willReturn(null);
        $this->totpAlgorithm->expects(self::never())->method('matchTimeStep');
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->idGenerator->expects(self::never())->method('generate');
        $this->repository->expects(self::never())->method('accept');

        self::assertNull($this->service()->accept('invitation-token', 'a unique passphrase!', 'secret', '123456'));
    }

    #[Test]
    public function itDoesNotGenerateOrPersistWhenTotpDoesNotMatch(): void
    {
        $now = new DateTimeImmutable('2026-09-08 12:00:00+00:00');
        $this->clock->expects(self::once())->method('now')->willReturn($now);
        $this->repository->expects(self::once())->method('findTargetAdministratorId')->willReturn(self::ADMINISTRATOR_ID);
        $this->totpAlgorithm->expects(self::once())->method('matchTimeStep')->willReturn(null);
        $this->recoveryCodeAlgorithm->expects(self::never())->method('generate');
        $this->secretCipher->expects(self::never())->method('encrypt');
        $this->idGenerator->expects(self::never())->method('generate');
        $this->repository->expects(self::never())->method('accept');

        self::assertNull($this->service()->accept('invitation-token', 'a unique passphrase!', 'secret', '000000'));
    }

    private function service(): AcceptAdministratorInvitation
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);
        $hasher = $this->createStub(AdministratorPasswordHasher::class);
        $hasher->method('hash')->willReturn('$argon2id$hash');

        return new AcceptAdministratorInvitation(
            new HashAdministratorPassword(new AdministratorPasswordPolicy($commonPasswordChecker), $hasher),
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
        return array_map(static fn (int $index): string => sprintf('%08X-AAAAAAAA-BBBBBBBB-CCCCCCCC', $index), range(0, 9));
    }

    /** @return list<string> */
    private function recoveryCodeIds(): array
    {
        return array_map(static fn (int $index): string => sprintf('01990d4a-0000-7000-8000-%012d', 200 + $index), range(0, 9));
    }
}
