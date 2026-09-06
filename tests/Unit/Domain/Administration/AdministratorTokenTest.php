<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorTokenTest extends TestCase
{
    #[Test]
    public function itAcceptsAnAvailableInitialSetupTokenWithoutAnAdministrator(): void
    {
        $token = $this->token(expiresAt: '2026-09-04 00:30:00.000000');

        self::assertFalse($token->isAvailableAt($this->dateTime('2026-09-03 23:59:59.999999')));
        self::assertTrue($token->isAvailableAt($this->dateTime('2026-09-04 00:00:00.000000')));
        self::assertTrue($token->isAvailableAt($this->dateTime('2026-09-04 00:29:59.999999')));
        self::assertFalse($token->isAvailableAt($this->dateTime('2026-09-04 00:30:00.000000')));
    }

    #[Test]
    public function itAcceptsAnInvitationForASpecificAuthenticationVersion(): void
    {
        $token = $this->token(
            expiresAt: '2026-09-04 00:30:00.000000',
            purpose: AdministratorTokenPurpose::Invitation,
            administratorId: '01990d4a-0000-7000-8000-000000000131',
            authenticationVersion: 3,
        );

        self::assertSame(3, $token->authenticationVersion);
    }

    #[Test]
    public function itRejectsAnInvitationWithoutAnAdministrator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('招待・回復トークンには対象管理者が必要です。');

        $this->token(
            expiresAt: '2026-09-04 00:30:00.000000',
            purpose: AdministratorTokenPurpose::Invitation,
            authenticationVersion: 1,
        );
    }

    #[Test]
    public function itRejectsAnInvitationWithoutAnAuthenticationVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('招待・回復トークンには有効な管理者認証版が必要です。');

        $this->token(
            expiresAt: '2026-09-04 00:30:00.000000',
            purpose: AdministratorTokenPurpose::Invitation,
            administratorId: '01990d4a-0000-7000-8000-000000000131',
        );
    }

    #[Test]
    public function itRejectsAnAuthenticationVersionForInitialSetup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('初期設定トークンに管理者認証版は指定できません。');

        $this->token(
            expiresAt: '2026-09-04 00:30:00.000000',
            authenticationVersion: 1,
        );
    }

    #[Test]
    public function itRejectsANonSha256TokenHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('トークンハッシュはSHA-256の小文字16進表現で指定してください。');

        $this->token(expiresAt: '2026-09-04 00:30:00.000000', tokenHash: 'raw-token');
    }

    private function token(
        string $expiresAt,
        AdministratorTokenPurpose $purpose = AdministratorTokenPurpose::InitialSetup,
        string $tokenHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ?string $administratorId = null,
        ?int $authenticationVersion = null,
    ): AdministratorToken {
        return new AdministratorToken(
            id: '01990d4a-0000-7000-8000-000000000130',
            administratorId: $administratorId,
            purpose: $purpose,
            tokenHash: $tokenHash,
            createdByAdministratorId: null,
            authenticationVersion: $authenticationVersion,
            createdAt: $this->dateTime('2026-09-04 00:00:00.000000'),
            expiresAt: $this->dateTime($expiresAt),
            consumedAt: null,
            revokedAt: null,
        );
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
