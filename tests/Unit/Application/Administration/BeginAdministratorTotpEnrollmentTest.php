<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BeginAdministratorTotpEnrollmentTest extends TestCase
{
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    #[Test]
    public function itReturnsANewSecretAndItsProvisioningUri(): void
    {
        $algorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $algorithm->expects(self::once())->method('generateSecret')->willReturn(self::SECRET);
        $algorithm->expects(self::once())
            ->method('provisioningUri')
            ->with(self::SECRET, 'system.owner')
            ->willReturn('otpauth://totp/StreamNotifyBot:system.owner?secret=masked');

        $enrollment = (new BeginAdministratorTotpEnrollment($algorithm))->begin('system.owner');

        self::assertSame(self::SECRET, $enrollment->secret);
        self::assertSame(
            'otpauth://totp/StreamNotifyBot:system.owner?secret=masked',
            $enrollment->provisioningUri,
        );
    }

    #[Test]
    public function itPropagatesAnInvalidAccountNameWithoutReturningTheSecret(): void
    {
        $algorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $algorithm->expects(self::once())->method('generateSecret')->willReturn(self::SECRET);
        $algorithm->expects(self::once())
            ->method('provisioningUri')
            ->with(self::SECRET, ' ')
            ->willThrowException(new InvalidArgumentException('TOTPアカウント名を指定してください。'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TOTPアカウント名を指定してください。');

        (new BeginAdministratorTotpEnrollment($algorithm))->begin(' ');
    }
}
