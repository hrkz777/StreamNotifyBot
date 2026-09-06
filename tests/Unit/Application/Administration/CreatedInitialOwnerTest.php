<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\CreatedInitialOwner;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreatedInitialOwnerTest extends TestCase
{
    #[Test]
    public function itReturnsRecoveryCodesOnlyOnce(): void
    {
        $codes = $this->recoveryCodes();
        $result = new CreatedInitialOwner('01990d4a-0000-7000-8000-000000000150', $codes);

        self::assertSame($codes, $result->consumeRecoveryCodes());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('回復コードは既に取得されています。');
        $result->consumeRecoveryCodes();
    }

    #[Test]
    public function itRequiresExactlyTenRecoveryCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('作成済み初期ownerには10件の回復コードが必要です。');

        new CreatedInitialOwner(
            '01990d4a-0000-7000-8000-000000000150',
            array_fill(0, 9, 'recovery-code'),
        );
    }

    /** @return list<string> */
    private function recoveryCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $codes[] = sprintf('%08X-AAAAAAAA-BBBBBBBB-CCCCCCCC', $index);
        }

        return $codes;
    }
}
