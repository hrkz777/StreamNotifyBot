<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\System;

use App\Domain\System\OperationalSettingCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationalSettingCatalogTest extends TestCase
{
    #[Test]
    public function itDefinesEveryPersistedOperationalSetting(): void
    {
        $definitions = OperationalSettingCatalog::all();

        self::assertCount(28, $definitions);
        self::assertSame(60, $definitions['polling_scheduled_youtube']->minimum);
        self::assertSame(604800, $definitions['polling_error_twitcasting']->maximum);
        self::assertSame(0, $definitions['quota_twitch_reserved']->minimum);
        self::assertSame(3650, $definitions['retention_audit_logs']->maximum);
    }

    #[Test]
    public function itRejectsAnUnknownSettingKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OperationalSettingCatalog::get('unknown_setting');
    }

    #[Test]
    public function itProvidesTheConfiguredValueRange(): void
    {
        $definition = OperationalSettingCatalog::get('retention_delivery_results');

        self::assertTrue($definition->accepts(7));
        self::assertTrue($definition->accepts(30));
        self::assertFalse($definition->accepts(6));
        self::assertFalse($definition->accepts(31));
    }
}
