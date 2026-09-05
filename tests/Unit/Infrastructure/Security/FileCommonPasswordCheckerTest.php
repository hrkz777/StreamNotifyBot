<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\FileCommonPasswordChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileCommonPasswordCheckerTest extends TestCase
{
    #[Test]
    public function itChecksTheLocalListCaseInsensitivelyWithoutAnExternalRequest(): void
    {
        $checker = new FileCommonPasswordChecker(
            dirname(__DIR__, 4).'/resources/security/common-passwords.txt',
        );

        self::assertTrue($checker->isCommon('PASSWORD1234'));
        self::assertFalse($checker->isCommon('a unique long passphrase!'));
    }

    #[Test]
    public function itFailsClosedWhenTheLocalListCannotBeRead(): void
    {
        $checker = new FileCommonPasswordChecker(__DIR__.'/missing-password-list.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ローカルパスワード拒否リストを読み込めません。');

        $checker->isCommon('a unique long passphrase!');
    }
}
