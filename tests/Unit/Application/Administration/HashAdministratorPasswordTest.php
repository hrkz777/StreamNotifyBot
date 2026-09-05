<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\HashAdministratorPassword;
use App\Domain\Administration\AdministratorPasswordHasher;
use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\CommonPasswordChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HashAdministratorPasswordTest extends TestCase
{
    #[Test]
    public function itValidatesBeforeHashingThePassword(): void
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $commonPasswordChecker->method('isCommon')->willReturn(false);
        $passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $passwordHasher->expects(self::once())
            ->method('hash')
            ->with('a unique passphrase!')
            ->willReturn('$argon2id$generated');
        $service = new HashAdministratorPassword(
            new AdministratorPasswordPolicy($commonPasswordChecker),
            $passwordHasher,
        );

        self::assertSame('$argon2id$generated', $service->hash('a unique passphrase!'));
    }

    #[Test]
    public function itDoesNotHashARejectedPassword(): void
    {
        $commonPasswordChecker = $this->createStub(CommonPasswordChecker::class);
        $passwordHasher = $this->createMock(AdministratorPasswordHasher::class);
        $passwordHasher->expects(self::never())->method('hash');
        $service = new HashAdministratorPassword(
            new AdministratorPasswordPolicy($commonPasswordChecker),
            $passwordHasher,
        );

        $this->expectException(AdministratorPasswordRejected::class);

        $service->hash('too short');
    }
}
