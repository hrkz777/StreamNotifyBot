<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\IssuedInitialSetupToken;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssuedInitialSetupTokenTest extends TestCase
{
    #[Test]
    public function itReturnsThePlainTokenOnlyOnce(): void
    {
        $expiresAt = new DateTimeImmutable('2026-09-08 00:30:00+00:00');
        $issuedToken = new IssuedInitialSetupToken('url-safe-token', $expiresAt);

        self::assertSame('url-safe-token', $issuedToken->consumeToken());
        self::assertSame($expiresAt, $issuedToken->expiresAt);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('初期設定トークンは既に取得されています。');
        $issuedToken->consumeToken();
    }
}
