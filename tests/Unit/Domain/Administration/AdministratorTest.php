<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorTest extends TestCase
{
    #[Test]
    public function itNormalizesTheLoginIdAndDisplayName(): void
    {
        $administrator = $this->pendingAdministrator('  System.Owner  ', '  管理者  ');

        self::assertSame('system.owner', $administrator->loginId);
        self::assertSame('管理者', $administrator->displayName);
    }

    #[Test]
    public function itRejectsAnActiveAdministratorWithoutPasswordAndTotp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('有効な管理者にはパスワードとTOTPの登録が必要です。');

        new Administrator(
            id: '01990d4a-0000-7000-8000-000000000110',
            loginId: 'system.owner',
            displayName: '管理者',
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Active,
            passwordHash: null,
            authenticationVersion: 1,
            passwordChangedAt: null,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $this->dateTime(),
            updatedAt: $this->dateTime(),
            lockVersion: 0,
        );
    }

    #[Test]
    public function itRejectsANonArgon2idPasswordHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('パスワードハッシュはArgon2id形式で指定してください。');

        new Administrator(
            id: '01990d4a-0000-7000-8000-000000000110',
            loginId: 'system.owner',
            displayName: '管理者',
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Pending,
            passwordHash: '$2y$10$not-an-argon2id-hash',
            authenticationVersion: 1,
            passwordChangedAt: null,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $this->dateTime(),
            updatedAt: $this->dateTime(),
            lockVersion: 0,
        );
    }

    private function pendingAdministrator(string $loginId, string $displayName): Administrator
    {
        return new Administrator(
            id: '01990d4a-0000-7000-8000-000000000110',
            loginId: $loginId,
            displayName: $displayName,
            role: AdministratorRole::Owner,
            status: AdministratorStatus::Pending,
            passwordHash: null,
            authenticationVersion: 1,
            passwordChangedAt: null,
            totpEnrolledAt: null,
            lastLoginAt: null,
            disabledAt: null,
            deletedAt: null,
            createdAt: $this->dateTime(),
            updatedAt: $this->dateTime(),
            lockVersion: 0,
        );
    }

    private function dateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-04 00:00:00.123456', new DateTimeZone('UTC'));
    }
}
