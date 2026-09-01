<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterStreamerInputTest extends TestCase
{
    #[Test]
    public function itRejectsAnEmptyRegistrationIdentifierBeforeApiLookup(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RegisterStreamerInput(
            '01990d4a-0000-7000-8000-000000000301',
            SupportedLanguage::Japanese,
            null,
            true,
            [new StreamerName(SupportedLanguage::Japanese, '配信者')],
            Platform::YouTube,
            '　',
        );
    }
}
