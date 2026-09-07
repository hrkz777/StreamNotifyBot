<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Administration\VerifyAdministratorTotp;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Security\AdministratorTwoFactorFormRenderer;
use App\Infrastructure\Security\AdministratorTwoFactorProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AdministratorTwoFactorProviderTest extends TestCase
{
    private const string ADMINISTRATOR_ID = '01990d4a-0000-7000-8000-000000000950';

    #[Test]
    public function itRequiresTwoFactorAuthenticationForAdministratorSecurityUsers(): void
    {
        $provider = $this->provider(
            $this->createStub(AdministratorTotpCredentialRepository::class),
            $this->createStub(SecretCipher::class),
            $this->createStub(AdministratorTotpAlgorithm::class),
            $this->createStub(Clock::class),
        );
        $context = $this->createStub(AuthenticationContextInterface::class);
        $context->method('getUser')->willReturn($this->securityUser());

        self::assertTrue($provider->beginAuthentication($context));
        self::assertFalse($provider->needsPreparation());
    }

    #[Test]
    public function itValidatesThroughTheReplayProtectedAdministratorTotpBoundary(): void
    {
        $credential = new AdministratorTotpCredential(
            self::ADMINISTRATOR_ID,
            new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'primary'),
            100,
        );
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::once())
            ->method('findByAdministratorId')
            ->with(self::ADMINISTRATOR_ID)
            ->willReturn($credential);
        $repository->expects(self::once())
            ->method('acceptTimeStep')
            ->with(self::ADMINISTRATOR_ID, 101)
            ->willReturn(true);
        $secretCipher = $this->createMock(SecretCipher::class);
        $secretCipher->expects(self::once())
            ->method('decrypt')
            ->with($credential->encryptedSecret, SecretPurpose::AdministratorTotpSecret, self::ADMINISTRATOR_ID)
            ->willReturn('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $totpAlgorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $totpAlgorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(
                'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
                '123456',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(101);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-07 02:00:00+00:00'));

        self::assertTrue($this->provider($repository, $secretCipher, $totpAlgorithm, $clock)
            ->validateAuthenticationCode($this->securityUser(), '123456'));
    }

    #[Test]
    public function itRejectsUnsupportedUsersWithoutReadingCredentials(): void
    {
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::never())->method('findByAdministratorId');
        $provider = $this->provider(
            $repository,
            $this->createStub(SecretCipher::class),
            $this->createStub(AdministratorTotpAlgorithm::class),
            $this->createStub(Clock::class),
        );

        self::assertFalse($provider->validateAuthenticationCode(new \stdClass(), '123456'));
    }

    private function provider(
        AdministratorTotpCredentialRepository $repository,
        SecretCipher $secretCipher,
        AdministratorTotpAlgorithm $totpAlgorithm,
        Clock $clock,
    ): AdministratorTwoFactorProvider {
        return new AdministratorTwoFactorProvider(
            new VerifyAdministratorTotp($repository, $secretCipher, $totpAlgorithm, $clock),
            new AdministratorTwoFactorFormRenderer(new Environment(new ArrayLoader())),
        );
    }

    private function securityUser(): AdministratorSecurityUser
    {
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');

        return AdministratorSecurityUser::fromAdministrator(new Administrator(
            self::ADMINISTRATOR_ID,
            'test.owner',
            'テスト管理者',
            AdministratorRole::Owner,
            AdministratorStatus::Active,
            password_hash('test-only-password', PASSWORD_ARGON2ID),
            1,
            $now,
            $now,
            null,
            null,
            null,
            $now,
            $now,
            0,
        ));
    }
}
