<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\CryptographicAdministratorSetupTokenGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CryptographicAdministratorSetupTokenGeneratorTest extends TestCase
{
    #[Test]
    public function itGeneratesDistinctUrlSafe256BitTokens(): void
    {
        $generator = new CryptographicAdministratorSetupTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $first);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $second);
        self::assertNotSame($first, $second);
        self::assertSame(32, strlen(self::decode($first)));
        self::assertSame(32, strlen(self::decode($second)));
    }

    private static function decode(string $token): string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/').'=', true);

        return $decoded === false ? self::fail('URL安全なBase64トークンを復号できません。') : $decoded;
    }
}
