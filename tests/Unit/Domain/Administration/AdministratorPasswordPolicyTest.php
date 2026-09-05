<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AdministratorPasswordPolicy;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\AdministratorPasswordRejection;
use App\Domain\Administration\CommonPasswordChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdministratorPasswordPolicyTest extends TestCase
{
    #[Test]
    public function itAcceptsUnicodeWhitespaceAndSymbolsWithinTheLengthLimits(): void
    {
        $checker = $this->createMock(CommonPasswordChecker::class);
        $checker->expects(self::once())
            ->method('isCommon')
            ->with('管理 用 password!')
            ->willReturn(false);

        (new AdministratorPasswordPolicy($checker))->assertAcceptable('管理 用 password!');
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, AdministratorPasswordRejection}> */
    public static function rejectedPasswordProvider(): iterable
    {
        yield 'eleven characters' => ['12345678901', AdministratorPasswordRejection::TooShort];
        yield '129 unicode characters' => [str_repeat('管', 129), AdministratorPasswordRejection::TooLong];
    }

    #[Test]
    #[DataProvider('rejectedPasswordProvider')]
    public function itRejectsPasswordsOutsideTheLengthLimits(
        string $password,
        AdministratorPasswordRejection $expectedReason,
    ): void {
        $checker = $this->createMock(CommonPasswordChecker::class);
        $checker->expects(self::never())->method('isCommon');
        $policy = new AdministratorPasswordPolicy($checker);

        try {
            $policy->assertAcceptable($password);
            self::fail('AdministratorPasswordRejected was not thrown.');
        } catch (AdministratorPasswordRejected $exception) {
            self::assertSame($expectedReason, $exception->reason);
        }
    }

    #[Test]
    public function itRejectsALocallyKnownCommonPassword(): void
    {
        $checker = $this->createStub(CommonPasswordChecker::class);
        $checker->method('isCommon')->willReturn(true);
        $policy = new AdministratorPasswordPolicy($checker);

        try {
            $policy->assertAcceptable('password1234');
            self::fail('AdministratorPasswordRejected was not thrown.');
        } catch (AdministratorPasswordRejected $exception) {
            self::assertSame(AdministratorPasswordRejection::Common, $exception->reason);
        }
    }
}
