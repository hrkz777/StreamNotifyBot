<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Csv;

use App\Application\Csv\NotificationRouteCsvCodec;
use App\Domain\Notification\NotificationRoute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRouteCsvCodecTest extends TestCase
{
    #[Test]
    public function itExportsNotificationRoutesAsShiftJis(): void
    {
        $contents = (new NotificationRouteCsvCodec())->export([
            new NotificationRoute('01990d4a-0000-7000-8000-000000000201', '=通知設定', null, 'purple', true),
        ]);
        $csv = mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');

        self::assertStringStartsWith("notification_route_id,name,description,color,is_enabled\r\n", $csv);
        self::assertStringContainsString("'=通知設定,,purple,1\r\n", $csv);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $csv));
    }
}
