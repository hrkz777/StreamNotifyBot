<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\OtpPhpAdministratorTotpAlgorithm;
use DateTimeImmutable;
use InvalidArgumentException;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class OtpPhpAdministratorTotpAlgorithmTest extends TestCase
{
    private const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private ClockInterface $clock;
    private OtpPhpAdministratorTotpAlgorithm $algorithm;

    protected function setUp(): void
    {
        $this->clock = $this->createStub(ClockInterface::class);
        $this->clock->method('now')->willReturn(new DateTimeImmutable('@59'));
        $this->algorithm = new OtpPhpAdministratorTotpAlgorithm($this->clock);
    }

    #[Test]
    public function itMatchesTheRfc6238Sha1CodeAndAllowsSpaces(): void
    {
        self::assertSame(
            1,
            $this->algorithm->matchTimeStep(self::RFC_SECRET, '287 082', new DateTimeImmutable('@59')),
        );
    }

    #[Test]
    public function itMatchesOnePreviousAndOneFollowingPeriod(): void
    {
        $totp = TOTP::create(self::RFC_SECRET, 30, 'sha1', 6, clock: $this->clock);
        $now = new DateTimeImmutable('@60');

        self::assertSame(1, $this->algorithm->matchTimeStep(self::RFC_SECRET, $totp->at(59), $now));
        self::assertSame(2, $this->algorithm->matchTimeStep(self::RFC_SECRET, $totp->at(60), $now));
        self::assertSame(3, $this->algorithm->matchTimeStep(self::RFC_SECRET, $totp->at(90), $now));
        self::assertNull($this->algorithm->matchTimeStep(self::RFC_SECRET, $totp->at(29), $now));
    }

    #[Test]
    public function itRejectsMalformedCodes(): void
    {
        self::assertNull($this->algorithm->matchTimeStep(self::RFC_SECRET, '', new DateTimeImmutable('@59')));
        self::assertNull($this->algorithm->matchTimeStep(self::RFC_SECRET, '12345a', new DateTimeImmutable('@59')));
        self::assertNull($this->algorithm->matchTimeStep(self::RFC_SECRET, '１２３４５６', new DateTimeImmutable('@59')));
    }

    #[Test]
    public function itGeneratesIndependentUnpaddedBase32Secrets(): void
    {
        $first = $this->algorithm->generateSecret();
        $second = $this->algorithm->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{52}$/D', $first);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{52}$/D', $second);
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function itBuildsAProvisioningUriWithoutExposingTheSecretOutsideTheQuery(): void
    {
        $uri = $this->algorithm->provisioningUri(self::RFC_SECRET, ' system.owner ');

        self::assertStringStartsWith('otpauth://totp/StreamNotifyBot%3Asystem.owner?', $uri);
        self::assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        self::assertStringContainsString('issuer=StreamNotifyBot', $uri);
    }

    #[Test]
    public function itRejectsAnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TOTP秘密値の形式が不正です。');

        $this->algorithm->provisioningUri('', 'system.owner');
    }

    #[Test]
    public function itRejectsASecretOutsideTheGeneratedBase32Format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TOTP秘密値の形式が不正です。');

        $this->algorithm->matchTimeStep('not-base32', '123456', new DateTimeImmutable('@59'));
    }
}
