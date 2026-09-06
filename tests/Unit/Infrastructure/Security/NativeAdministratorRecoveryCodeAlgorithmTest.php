<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\NativeAdministratorRecoveryCodeAlgorithm;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeAdministratorRecoveryCodeAlgorithmTest extends TestCase
{
    private const string DISPLAY_CODE = '01234567-89ABCDEF-01234567-89ABCDEF';
    private const string CODE_HASH = 'cd6c1f7d1dc6717d6371d2647910ca71ba3bf0b611083d322466b8843b4285b6';

    private NativeAdministratorRecoveryCodeAlgorithm $algorithm;

    protected function setUp(): void
    {
        $this->algorithm = new NativeAdministratorRecoveryCodeAlgorithm();
    }

    #[Test]
    public function itGeneratesTenUniqueCodesWithOneHundredTwentyEightBitsOfRandomness(): void
    {
        $codes = $this->algorithm->generate();

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{8}(?:-[0-9A-F]{8}){3}$/D', $code);
        }
    }

    #[Test]
    public function itNormalizesCaseHyphensAndAsciiWhitespace(): void
    {
        self::assertSame(
            '0123456789ABCDEF0123456789ABCDEF',
            $this->algorithm->normalize(" 01234567-89abcdef\t01234567-89ABCDEF\r\n"),
        );
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function itRejectsAnInvalidCode(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('回復コードの形式が不正です。');

        $this->algorithm->normalize($code);
    }

    #[Test]
    public function itHashesTheNormalizedCodeWithSha256(): void
    {
        self::assertSame(self::CODE_HASH, $this->algorithm->hash(self::DISPLAY_CODE));
    }

    #[Test]
    public function itVerifiesAnEquivalentCodePresentation(): void
    {
        self::assertTrue($this->algorithm->verify(
            self::CODE_HASH,
            '01234567 89abcdef 01234567 89abcdef',
        ));
    }

    #[Test]
    public function itRejectsAnIncorrectOrMalformedCodeWithoutThrowing(): void
    {
        self::assertFalse($this->algorithm->verify(self::CODE_HASH, 'FEDCBA98-76543210-FEDCBA98-76543210'));
        self::assertFalse($this->algorithm->verify(self::CODE_HASH, 'invalid'));
        self::assertFalse($this->algorithm->verify(str_repeat('A', 64), self::DISPLAY_CODE));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['01234567-89ABCDEF-01234567-89ABCDE'];
        yield 'too long' => ['01234567-89ABCDEF-01234567-89ABCDEF0'];
        yield 'non hexadecimal' => ['01234567-89ABCDEF-01234567-89ABCDEG'];
        yield 'non ASCII' => ['０1234567-89ABCDEF-01234567-89ABCDEF'];
    }
}
